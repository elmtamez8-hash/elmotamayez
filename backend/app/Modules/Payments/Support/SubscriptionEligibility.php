<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Shared\Contracts\SubscriptionDirectory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a live subscription opens (T093 · FR-026 · FR-028).
 *
 * ⚠️ COVERAGE IS READ THROUGH THE COURSE, NEVER FROM A LIST STORED ON THE PLAN.
 * A `workspace` plan covers whatever that teacher publishes TODAY — so a course
 * added next week is included without anybody editing a row, and a course
 * deleted or unpublished drops out of the coverage while the subscription stays
 * alive on the rest (data-model §٧'s edge case). Materialising the list at
 * purchase would freeze a catalogue that is meant to move, and would have to
 * answer «what happens to the subscription» every time a teacher archives
 * anything.
 *
 * ⚠️ A COURSE-LEVEL «YES» IS NOT A SESSION-LEVEL «YES», AND THE DIFFERENCE IS
 * `session_type`. A teacher's price changes with the size of the room, so a plan
 * priced for a group of eight must not silently cover one-to-one hours at the
 * same money. {@see self::coveringSession()} matches the type; the course-level
 * read below deliberately does not, because opening a lesson's CONTENT has no
 * room size to compare against.
 *
 * ⚠️ KNOWN CEILING, WRITTEN DOWN RATHER THAN HIDDEN: the withholding lift is
 * course-level, so a student holding a group-only plan is not stopped at the
 * booking door from taking a one-to-one session — they book it, and the charge
 * branch then debits a credit for it exactly as it would with no plan at all.
 * The money is right; the surprise is one session wide and self-corrects at the
 * next booking. Closing it properly means teaching `AccountStanding` about
 * session types, which puts a LiveSessions enum inside a shared contract for a
 * case worth one session — do that if it ever bites.
 */
class SubscriptionEligibility implements SubscriptionDirectory
{
    /**
     * Every live subscription this student holds, newest window last.
     *
     * ⚠️ ONE READ, AND EVERY CALLER BELOW FILTERS IT IN MEMORY. A student holds
     * one or two of these, and the alternative is a query per course on the
     * hottest path in the product — `isWithheld` is asked by `IssueJoinTicket`
     * on every presence heartbeat.
     *
     * ⚠️ `withoutWorkspaceScope()` IS REQUIRED, NOT DEFENSIVE. This is asked
     * about a student, and a student is a member of no workspace: the context is
     * null, so the scope adds nothing here — but it is ALSO asked from the panel,
     * where the reader's workspace is a real number that would hide every other
     * teacher's subscription from a platform report.
     *
     * @return Collection<int, Subscription>
     */
    public function liveFor(int $studentUserId, ?DateTimeInterface $moment = null): Collection
    {
        return Subscription::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $studentUserId)
            ->liveOn($moment ?? now())
            ->with('plan')
            ->get();
    }

    /**
     * Whether anything this student holds opens this course's content.
     */
    public function coversCourse(int $studentUserId, int $courseId, ?DateTimeInterface $moment = null): bool
    {
        /*
        | ⚠️ THE SUBSCRIPTIONS ARE READ FIRST, AND THE ORDER IS THE WHOLE COST.
        | This sits at the top of `isWithheld()`, which `IssueJoinTicket` asks on
        | EVERY presence heartbeat — thirty students twice a minute, per room —
        | and almost none of them holds a subscription. Reading the course first
        | spends a query on every one of those beats to answer a question the
        | empty list below settles for free.
        */
        $live = $this->liveFor($studentUserId, $moment);

        if ($live->isEmpty()) {
            return false;
        }

        $course = Course::query()->withoutWorkspaceScope()->find($courseId);

        if ($course === null) {
            return false;
        }

        return $live->contains(fn (Subscription $subscription): bool => $this->reaches($subscription, $course));
    }

    /**
     * The course ids a student's subscriptions open, out of a list.
     *
     * The bulk form, for the same reason `AccountStanding` carries one: a
     * per-course call inside a loop is the N+1 the panel's query budget forbids.
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function coveredCourseIds(int $studentUserId, array $courseIds, ?DateTimeInterface $moment = null): array
    {
        if ($courseIds === []) {
            return [];
        }

        $live = $this->liveFor($studentUserId, $moment);

        if ($live->isEmpty()) {
            return [];
        }

        $courses = Course::query()->withoutWorkspaceScope()->whereIn('id', $courseIds)->get();

        $covered = [];

        foreach ($courses as $course) {
            if ($live->contains(fn (Subscription $subscription): bool => $this->reaches($subscription, $course))) {
                $covered[] = (int) $course->getKey();
            }
        }

        return $covered;
    }

    /**
     * The subscription that pays for this seat, or null when nothing does.
     *
     * Matches the ROOM SIZE as well as the course — see the class docblock. The
     * session's own date is the moment asked about, not `now()`: a delivery is
     * charged when the event fires, which can be minutes or a sweep later, and a
     * subscription that ran out overnight still paid for yesterday's lesson.
     */
    public function coveringSession(int $studentUserId, ClassSession $session): ?Subscription
    {
        $course = $session->course;

        if ($course === null) {
            return null;
        }

        return $this->liveFor($studentUserId, $session->starts_at)
            ->first(fn (Subscription $subscription): bool => $this->reaches($subscription, $course)
                && $subscription->plan?->session_type === $session->type);
    }

    /**
     * The bulk form of the above, for a whole room in one read.
     *
     * ⚠️ THE CHARGE PATH ASKS THIS ONCE PER SESSION, NEVER ONCE PER SEAT. Thirty
     * students in one class share the course, the workspace and the moment;
     * `ChargeSessionSeats` already had to learn that lesson for the billing mode,
     * the exam window and the workspace row, and this is the same shape.
     *
     * @param  list<int>  $studentUserIds
     * @return array<int, Subscription> keyed by student id
     */
    public function coveringSessionFor(array $studentUserIds, ClassSession $session): array
    {
        $course = $session->course;

        if ($course === null || $studentUserIds === []) {
            return [];
        }

        $subscriptions = Subscription::query()
            ->withoutWorkspaceScope()
            ->whereIn('student_user_id', $studentUserIds)
            ->where('workspace_id', $session->workspace_id)
            ->liveOn($session->starts_at)
            ->with('plan')
            ->get();

        $covering = [];

        foreach ($subscriptions as $subscription) {
            if (isset($covering[(int) $subscription->student_user_id])) {
                continue;
            }

            if (! $this->reaches($subscription, $course)) {
                continue;
            }

            if ($subscription->plan?->session_type !== $session->type) {
                continue;
            }

            $covering[(int) $subscription->student_user_id] = $subscription;
        }

        return $covering;
    }

    /**
     * {@inheritDoc}
     *
     * ⚠️ THE SESSION TYPE IS MATCHED HERE AND THE COURSE-LEVEL READ ABOVE DOES
     * NOT MATCH IT. A seat has a room size; a lesson's content does not. This is
     * the seat question, so a group plan does not claim a one-to-one hour.
     */
    public function subscriberIdsAmong(
        array $studentUserIds,
        int $courseId,
        string $sessionType,
        DateTimeInterface $moment,
    ): array {
        if ($studentUserIds === []) {
            return [];
        }

        $course = Course::query()->withoutWorkspaceScope()->find($courseId);

        if ($course === null) {
            return [];
        }

        /*
        | ⚠️ `liveOn($moment)` AND NOT `liveOn(now())`. The whole reason this
        | parameter exists: a session scheduled today for next month must be
        | judged against the window as it will stand THEN, or the platform books
        | a seat it will later charge a credit for.
        */
        $subscriptions = Subscription::query()
            ->withoutWorkspaceScope()
            ->whereIn('student_user_id', $studentUserIds)
            ->where('workspace_id', $course->workspace_id)
            ->liveOn($moment)
            ->with('plan')
            ->get();

        $covered = [];

        foreach ($subscriptions as $subscription) {
            if (! $this->reaches($subscription, $course)) {
                continue;
            }

            if ($subscription->plan?->session_type->value !== $sessionType) {
                continue;
            }

            $covered[(int) $subscription->student_user_id] = true;
        }

        return array_map(intval(...), array_keys($covered));
    }

    /**
     * {@inheritDoc}
     *
     * ⚠️ THE PLAN SIDE IS ASKED SECOND AND ONLY WHEN THE PRICE IS ZERO. Most
     * courses that are sold outright answer on the column alone, and this is
     * reached from a route any authenticated account can call.
     */
    public function courseRequiresPurchase(int $courseId): bool
    {
        $course = Course::query()->withoutWorkspaceScope()->find($courseId);

        if ($course === null) {
            return false;
        }

        if ((int) $course->price_minor > 0) {
            return true;
        }

        return $this->hasSellablePlanFor($courseId);
    }

    /**
     * {@inheritDoc}
     */
    public function hasSellablePlanFor(int $courseId, ?string $sessionType = null): bool
    {
        $course = Course::query()->withoutWorkspaceScope()->find($courseId);

        if ($course === null) {
            return false;
        }

        $query = Plan::query()
            ->withoutWorkspaceScope()
            ->sellable()
            ->where('workspace_id', $course->workspace_id)
            /*
            | ⚠️ THE GROUPING PARENTHESES ARE LOAD-BEARING. Written flat, the
            | `orWhere` would OR at the TOP level and discard the workspace and
            | the sellable conditions — publishing every priced plan on the
            | platform as if it covered this course.
            */
            ->where(fn (Builder $inner) => $inner
                ->where('coverage_type', PlanCoverage::Workspace->value)
                ->orWhere('coverage_uuid', $course->uuid));

        if ($sessionType !== null) {
            $query->where('session_type', $sessionType);
        }

        return $query->exists();
    }

    /**
     * The coverage predicate itself, in one place.
     *
     * ⚠️ AN UNPUBLISHED OR DELETED COURSE FALLS OUT OF COVERAGE AND THE
     * SUBSCRIPTION SURVIVES ON THE REST (data-model §٧). Cancelling the whole
     * subscription would punish the student for the teacher's editing, and
     * leaving the course covered would sell access to something withdrawn.
     */
    private function reaches(Subscription $subscription, Course $course): bool
    {
        if ((int) $subscription->workspace_id !== (int) $course->workspace_id) {
            return false;
        }

        if ($course->status !== 'published') {
            return false;
        }

        $plan = $subscription->plan;

        if ($plan === null || ! $plan->coverage_type->needsCourse()) {
            return $plan !== null;
        }

        return $plan->coverage_uuid === $course->uuid;
    }
}
