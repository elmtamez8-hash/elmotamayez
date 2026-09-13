<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Payments\Models\SessionUnlock;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionContentAccess;
use App\Shared\Data\SessionContentOffer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ٠٣٥ — «هل يُفتَحُ لك ما يخصُّ هذه الحصّة؟»، من الجانبِ الذي يعرفُ الجواب.
 *
 * ⚠️ ONE COLUMN ANSWERS IT: `attendances.credit_verdict_at`. Stamped means the
 * seat was charged — either the student sat through the lesson, or they fell
 * short and gave no notice, and FR-008ج says the silent no-show receives the
 * hour they paid for. Null means judged and exempt: no charge, and the content
 * stays shut until they consent to spend a credit. The two sets are identical
 * by construction, which is why there is no second predicate to keep in step.
 *
 * ⛔ AND `delivered_at IS NOT NULL AND attended_seats IS NULL` IS «DELIVERED BUT
 * NOT JUDGED YET», WHICH ENTITLES — both halves, never the second alone. `scripts/deploy.sh` raises the containers before it runs the
 * migrations and Eloquent returns null for a column that does not exist, so
 * without that fallback every student of every session delivered before this
 * shipment — and every session inside the deploy window — would be CHARGED
 * (the pre-035 rule, which is correct) AND LOCKED OUT (which is not). The same
 * fallback `ChargeSessionSeats` reads, from the other side.
 *
 * ⚠️ EVERY READ DECLARES `withoutWorkspaceScope()` AND WRITES ITS OWNERSHIP
 * PREDICATE OUT BY HAND. The scope guards nothing here in either direction: for
 * a student who registered themselves it is inert, and for one carrying a
 * `last_workspace_id` — and `workspace_members` really does hold student rows —
 * it bites the WRONG way, hiding an unlock they bought from another teacher and
 * offering to sell it to them a second time.
 */
class EloquentSessionContentAccess implements SessionContentAccess
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly CohortDirectory $cohorts,
    ) {}

    public function mayOpenSessionContent(User $student, int $classSessionId): bool
    {
        return $this->openableSessionIds($student, [$classSessionId]) !== [];
    }

    /**
     * ⚠️ THE BULK FORM IS A CORRECTNESS CONDITION AND NOT AN OPTIMISATION.
     * Reading the curriculum is the hottest read in the product, and its cost is
     * flat in the size of the tree under a budget test that compares ten items
     * against two hundred. One question per item makes that hundreds of queries
     * on one course, and the build is red in the same minute.
     *
     * Three queries whatever the page size.
     *
     * @param  list<int>  $classSessionIds
     * @return list<int>
     */
    public function openableSessionIds(User $student, array $classSessionIds): array
    {
        $classSessionIds = array_values(array_unique(array_map('intval', $classSessionIds)));

        if ($classSessionIds === []) {
            return [];
        }

        /*
        | 1. DELIVERED BUT NOT JUDGED ⇒ the seat entitles. See the class docblock.
        |
        | ⛔ AND `delivered_at IS NOT NULL` IS HALF THE PREDICATE, NOT DECORATION.
        | Written as «not judged» alone it also covers every session that has not
        | HAPPENED yet — a teacher who attaches next week's worksheet to next
        | week's lesson would be publishing it to the whole register today, and
        | the register is precisely who this gate exists to narrow. The deploy
        | window is sessions ALREADY DELIVERED whose verdict column does not exist
        | yet; a session never delivered is FR-008د's last row, «لا محتوى أصلاً»,
        | and it stays shut.
        */
        $unjudged = DB::table('class_sessions')
            ->whereIn('id', $classSessionIds)
            ->whereNotNull('delivered_at')
            ->whereNull('attended_seats')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        // 2. Charged ⇒ the hour is theirs.
        $charged = DB::table('attendances')
            ->whereIn('class_session_id', $classSessionIds)
            ->where('student_user_id', $student->getKey())
            ->whereNotNull('credit_verdict_at')
            ->pluck('class_session_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        // 3. Bought, or opened automatically (a subscription seat, an ejection,
        //    a charged absence).
        $unlocked = SessionUnlock::query()
            ->withoutWorkspaceScope()
            ->whereIn('class_session_id', $classSessionIds)
            ->where('student_user_id', $student->getKey())
            ->pluck('class_session_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($unjudged, $charged, $unlocked)));
    }

    public function unlockableSessionIds(User $student, array $classSessionIds): array
    {
        $classSessionIds = array_values(array_unique(array_map('intval', $classSessionIds)));

        if ($classSessionIds === []) {
            return [];
        }

        /*
        | ١ — A SEAT OF ANY STATUS, AND IT IS ASKED FIRST FOR THE BUDGET AS MUCH
        | AS FOR THE MEANING. The spelling is `ClassSession::holdsSeat()` — a
        | booking row, whatever became of it — and somebody who gave notice in
        | time keeps the right to buy the hour back (FR-013ب). That is also the
        | overwhelmingly common case at the unlock endpoint, so answering it
        | before the three reads below keeps `UnlockQueryBudgetTest` at ONE query
        | for this question, which is what the private method it replaced cost.
        */
        $seated = array_values(array_unique(DB::table('session_bookings')
            ->whereIn('class_session_id', $classSessionIds)
            ->where('student_user_id', $student->getKey())
            ->pluck('class_session_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all()));

        $rest = array_values(array_diff($classSessionIds, $seated));

        if ($rest === []) {
            return $seated;
        }

        // ٢ — enrolment in the session's own course, plus a group that held it.
        $sessions = DB::table('class_sessions')
            ->whereIn('id', $rest)
            ->get(['id', 'course_id', 'cohort_id']);

        $courses = array_flip($this->enrollments->activeCourseIdsFor($student));
        $cohorts = array_flip($this->cohorts->everMemberCohortIdsFor($student));

        $out = $seated;

        foreach ($sessions as $row) {
            // No course: nothing to charge against and no enrolment to ask
            // about — the same refusal `unlockOfferFor()` already gives it.
            if ($row->course_id === null || ! isset($courses[(int) $row->course_id])) {
                continue;
            }

            // An unassigned session belongs to the COURSE rather than to a
            // group, so enrolment is the whole question there. `cohort_id` is
            // nullable because every session predating groups carries null.
            if ($row->cohort_id === null || isset($cohorts[(int) $row->cohort_id])) {
                $out[] = (int) $row->id;
            }
        }

        return $out;
    }

    public function unlockOfferFor(User $student, int $classSessionId): ?SessionContentOffer
    {
        if ($this->mayOpenSessionContent($student, $classSessionId)) {
            // Already open. An offer here would invite a second charge for
            // something the student already owns (FR-011).
            return null;
        }

        $session = DB::table('class_sessions')
            ->where('id', $classSessionId)
            ->first(['id', 'course_id', 'delivered_at', 'workspace_id']);

        // No session, no course, or never delivered: three refusals wearing one
        // answer. Offering a price for an hour the teacher called off would tell
        // the student it happened.
        if ($session === null || $session->course_id === null || $session->delivered_at === null) {
            return null;
        }

        /*
        | ⛔ THE CONTRACT ABOVE THIS METHOD HAS PROMISED «never entitled ⇒ null»
        | SINCE IT WAS WRITTEN, AND THIS IS THE LINE THAT KEEPS IT. The check
        | lived privately inside `SessionContentController` instead, so the DOOR
        | was right and every other reader of the offer was wrong: the
        | curriculum promised a student in another group that a credit would
        | open the hour, `stampAll(withOffer: true)` quoted them a price for it
        | on the session card, and pressing either answered 403.
        |
        | One spelling, on the contract, so the screen and the door cannot
        | disagree again — which is the ٠١٨ defect this module's every docblock
        | is written against.
        */
        if ($this->unlockableSessionIds($student, [$classSessionId]) === []) {
            return null;
        }

        $opens = $this->contentOf($classSessionId);

        if ($opens === []) {
            // Nothing to sell. The material was archived, or there was never any
            // — and FR-039 says an unlock does not extend retention, so selling
            // access to something already gone is selling what cannot be
            // delivered.
            return null;
        }

        $balance = DB::table('credit_balances')
            ->where('course_id', $session->course_id)
            ->where('student_user_id', $student->getKey())
            ->first(['remaining_credits', 'held_credits']);

        $owned = (int) ($balance->remaining_credits ?? 0);
        $held = (int) ($balance->held_credits ?? 0);

        return new SessionContentOffer(
            // One consent opens the whole hour — never a price per item
            // (FR-013). A per-file tariff would make the student do arithmetic
            // about a lesson they have not seen.
            credits: 1,
            ownedCredits: $owned,
            availableCredits: $owned - $held,
            opens: $opens,
            availableUntil: $this->availableUntil($classSessionId),
            /*
            | ⚠️ ALWAYS, never only when the balance is empty. A payload whose
            | SHAPE changes with what it found is a distinguishing answer.
            |
            | ⛔ AND THE PATH IS MEASURED, NOT GUESSED: the first draft wrote
            | `/credits`, which no file under `frontend/src/app` answers — a road
            | out that 404s is the «endpoint nobody calls» defect wearing its
            | mirror image, and FR-013 forbids a refusal a student can do nothing
            | with.
            */
            purchaseUrl: '/billing/purchase',
        );
    }

    /**
     * @param  iterable<Model>  $sessions
     */
    public function stampAll(iterable $sessions, User $student, bool $withOffer = false): void
    {
        /** @var list<Model> $rows */
        $rows = [];

        foreach ($sessions as $session) {
            $rows[] = $session;
        }

        if ($rows === []) {
            return;
        }

        $ids = array_map(static fn (Model $row): int => (int) $row->getKey(), $rows);
        $open = $this->openableSessionIds($student, $ids);

        foreach ($rows as $row) {
            $locked = ! in_array((int) $row->getKey(), $open, true);

            $row->setAttribute('content_locked', $locked);
            /*
            | ⚠️ THE OFFER IS RESOLVED ONLY WHEN THE CALLER ASKS, AND ONLY FOR THE
            | LOCKED ROWS. An offer per row is the `ClassSessionResource` N+1 this
            | repository already paid for once — measured again here: stamping it
            | on `/schedule` took that endpoint from 23 queries to 61.
            |
            | Null on an unasked page means «not asked», which is the same
            | convention `unlock_open` beside it already uses.
            */
            $row->setAttribute(
                'content_offer',
                $locked && $withOffer ? $this->unlockOfferFor($student, (int) $row->getKey()) : null,
            );
        }
    }

    /**
     * What a credit would open: KINDS AND COUNTS, never titles.
     *
     * ⚠️ A LIST OF THE WORKSHEET TITLES OF AN HOUR IS A LESSON PLAN FOR A LESSON
     * ITS OWNER DID NOT ATTEND. The public-field allowlist already draws exactly
     * that line on a lesson id, with the reason written beside it.
     *
     * ⚠️ AND AN ARCHIVED RECORDING IS NOT COUNTED. FR-039 says an unlock does
     * not extend retention, so a recording whose asset has been archived is not
     * something a credit can buy — and a count that included it would sell an
     * hour that no longer exists.
     *
     * @return array<string, int>
     */
    private function contentOf(int $classSessionId): array
    {
        /*
        | WARNING: THE JOIN IS POLYMORPHIC AND THERE IS NO `lessons.media_asset_id`,
        | which the first draft of this method assumed. Media is attached by
        | `(owner_type, owner_id)`, and «archived» on the LESSON is a value of
        | `status`, not a timestamp — measured, not remembered.
        |
        | `leftJoin` and not `join`: a lesson with no asset at all (an article
        | the teacher attached to the hour) is still content worth opening.
        */
        $lessons = DB::table('lessons')
            ->leftJoin('media_assets', function ($join): void {
                $join->on('media_assets.owner_id', '=', 'lessons.id')
                    ->where('media_assets.owner_type', '=', Lesson::class);
            })
            ->where('lessons.class_session_id', $classSessionId)
            ->where('lessons.status', '!=', ContentStatus::Archived->value)
            ->whereNull('media_assets.archived_at')
            ->distinct()
            ->count('lessons.id');

        $assignments = DB::table('assignments')
            ->where('class_session_id', $classSessionId)
            ->count();

        return array_filter([
            'lesson' => $lessons,
            'assignment' => $assignments,
        ], static fn (int $count): bool => $count > 0);
    }

    /**
     * How long the material stays available (FR-039ب) — the price of retention
     * being untouched is that the student is TOLD before they press.
     */
    private function availableUntil(int $classSessionId): ?string
    {
        $until = DB::table('lessons')
            ->join('media_assets', function ($join): void {
                $join->on('media_assets.owner_id', '=', 'lessons.id')
                    ->where('media_assets.owner_type', '=', Lesson::class);
            })
            ->where('lessons.class_session_id', $classSessionId)
            ->min('media_assets.retain_until');

        return is_string($until) && $until !== ''
            ? (date_create_immutable($until) ?: null)?->format(DATE_ATOM)
            : null;
    }
}
