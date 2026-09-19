<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\SubscriptionDirectory;
use DateTimeInterface;
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
    /*
    | ⚠️ `CoveredCourses` AND NOT THE COHORT DIRECTORY DIRECTLY. This class is the
    | hot path — asked twice on every booking — and it must stay free of any
    | knowledge of how a cohort uuid becomes a course. One class answers that, and
    | a second spelling here is the divergence every reader of this repository has
    | already paid for once.
    */
    public function __construct(
        private readonly CoveredCourses $covered,
        private readonly PlanReach $reach,
        private readonly CohortDirectory $cohorts,
    ) {}

    /**
     * Every live subscription this student holds, newest window last.
     *
     * ⚠️ ONE READ, AND EVERY CALLER BELOW FILTERS IT IN MEMORY. A student holds
     * one or two of these, and the alternative is a query per course at the
     * door — `isWithheld` is asked by `IssueJoinTicket` on every `join`.
     *
     * ⚠️ THIS USED TO SAY «on every presence heartbeat», AND IT IS NO LONGER
     * TRUE: `BroadcastController::presence()` asks `RoomRevocation` now, which
     * asks nothing about money (2026-09-15). The reasoning survives the move —
     * a query per course is still the wrong shape at a door — but the number it
     * was arguing against is gone.
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
        /*
        | ⚠️ THE ORDER IS LOADED BESIDE THE PLAN, AND ITS BYPASS IS ITS OWN. The
        | subscription query is already unscoped, and that says nothing about the
        | relation query underneath: a nested eager load runs the RELATED model's
        | global scope, so a plain `->with('order')` returns null for every row
        | outside the reader's own workspace — which for a student is every row,
        | and for a panel reader is every other teacher's. {@see self::reaches()}
        | then reads that null as «no course named» and falls through to the
        | directory, quietly paying the query this eager load exists to save.
        */
        return Subscription::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $studentUserId)
            ->liveOn($moment ?? now())
            ->with(['plan', 'order' => fn ($order) => $order->withoutWorkspaceScope()])
            ->get();
    }

    /**
     * Whether anything this student holds opens this course's content.
     */
    public function coversCourse(int $studentUserId, int $courseId, ?DateTimeInterface $moment = null): bool
    {
        /*
        | ⚠️ THE SUBSCRIPTIONS ARE READ FIRST, AND THE ORDER IS THE WHOLE COST.
        | This sits at the top of `refusalFor()`, which every money refusal in
        | the product asks — the booking door, the room door, a high-value file,
        | and the nightly sweep walking every booked seat — and almost none of
        | those students holds a subscription. Reading the course first spends a
        | query on every one of them to answer a question the empty list below
        | settles for free.
        |
        | ⚠️ AND IT USED TO NAME THE PRESENCE HEARTBEAT, WHICH NO LONGER REACHES
        | HERE (2026-09-15): the beat asks `RoomRevocation`, which asks nothing
        | about money. A docblock citing a measurement must be re-measured when
        | the code around it moves, or it describes the world before itself.
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
            ->with(['plan', 'order' => fn ($order) => $order->withoutWorkspaceScope()])
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
            ->with(['plan', 'order' => fn ($order) => $order->withoutWorkspaceScope()])
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

        /*
        | ⛔ THE COURSE'S GROUPS ARE ANCHORS TOO, AND WITHOUT THEM A COURSE SOLD
        | ONLY IN GROUPS READS AS FREE. This answer is what
        | `courseRequiresPurchase()` hands to the free-enrolment door
        | (`EnrollmentController`), so a teacher whose every price is written per
        | group was giving that course away to anybody who pressed the button —
        | no order, no subscription, nothing to notice.
        |
        | ⚠️ THE UUIDS COME FROM THE DIRECTORY, NEVER FROM A JOIN ON `cohorts`.
        | That table is Learning's and this module may not name it; the contract
        | is the whole of the crossing.
        |
        | ⚠️ AND THE PREDICATE ITSELF IS {@see PlanReach}, CALLED FROM HERE AND
        | FROM THE COHORT BRIDGE. A parallel arm written out here would be a
        | third spelling of the coverage rule, which is the divergence FR-016
        | exists over — and it is where the grouping parentheses that used to be
        | commented at this very line now live.
        */
        $anchors = array_values(array_unique(array_merge(
            [(string) $course->uuid],
            $this->cohorts->cohortUuidsFor($courseId),
        )));

        return $this->reach
            ->reaching([(int) $course->workspace_id], $anchors, $sessionType)
            ->exists();
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

        if ($plan === null) {
            return false;
        }

        /*
        | ⛔ `return $plan !== null` WAS THE WHOLE TEST FOR EVERY NON-COURSE PLAN,
        | and the third coverage turned that into «a group subscription covers
        | every course this teacher has, for its whole life». Workspace coverage
        | really does mean all of them; group coverage means exactly one.
        |
        | ⚠️ AND THIS IS A HOT PATH — asked twice on every booking — so nothing
        | below costs a query in the ordinary case: the workspace arm answers off
        | the plan row already loaded, and the two narrow ones answer off the
        | order row loaded beside it. The directory is reached only when that
        | column is empty.
        */
        if ($plan->coverage_type === PlanCoverage::Workspace) {
            return true;
        }

        /*
        | ⚠️ THE ORDER'S OWN COLUMN ANSWERS FIRST, AND THE DIRECTORY IS THE
        | EMERGENCY EXIT BEHIND IT — never «empty means no». `orders.course_id` is
        | written at purchase from exactly this question ({@see CoveredCourses}),
        | so for a group plan it already holds the cohort's course and the answer
        | costs no query at all: the row is eager-loaded beside the plan, once per
        | read. Asking the directory instead is one cohort lookup PER SUBSCRIBER,
        | and `coveringSessionFor()` runs this over a whole room.
        |
        | ⛔ AND `subscriptions` CARRIES NEITHER `course_id` NOR `cohort_id`. The
        | order is the only row in the chain that names what was bought, which is
        | why this reads through the relation rather than a column on the
        | subscription — a column an earlier plan for this claimed existed, and
        | which does not.
        |
        | ⚠️ A NULL IS NOT A REFUSAL. Workspace coverage writes no course id and
        | any subscription older than that writing has none, so a null falls
        | through to the plan's own coverage instead of answering «no» — which
        | would close a course the student paid for, silently, for every row that
        | predates this line.
        |
        | ⚠️ AND THE BUYER KEEPS WHAT THEY BOUGHT. A teacher who repoints a plan
        | at another course or another group after a sale moves the plan, not the
        | subscription: the order still names the course that was paid for. That
        | is deliberate, and it is the opposite of the workspace case one branch
        | above, where the catalogue is meant to move under the subscription.
        */
        $orderCourseId = $subscription->order?->course_id;

        if ($orderCourseId !== null) {
            return (int) $orderCourseId === (int) $course->getKey();
        }

        $covered = $this->covered->courseUuid($plan);

        return $covered !== null && $covered === $course->uuid;
    }
}
