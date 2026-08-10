<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Exceptions\AccessWithheldException;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AccountStanding;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use RuntimeException;

/**
 * The single door to watching anything.
 *
 * The entitlement check lives here rather than in a FormRequest because the
 * Action is the one entrance shared by the API, the seeders and the panel — a
 * rule enforced only in validation is a rule the panel walks around.
 *
 * Re-checked on every issue, never cached against the session: an enrolment that
 * lapses mid-course must stop the next request (FR-010).
 */
class IssuePlaybackGrant extends Action
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly SessionAttendanceDirectory $bookings,
        private readonly AccountStanding $standing,
    ) {}

    /**
     * @throws DomainException when the asset exists but is not playable yet
     * @throws RuntimeException when the viewer is not entitled
     */
    public function handle(
        Lesson $lesson,
        User $viewer,
        AuthSession $session,
        ?string $ipHash = null,
        // The item's own file when omitted. Named explicitly for an attachment,
        // which FR-034 puts behind the same short-lived grant as everything else
        // — a worksheet reachable by a permanent path would be the leak this
        // module exists to remove, wearing a smaller hat.
        ?MediaAsset $asset = null,
    ): PlaybackGrant {
        $asset ??= $lesson->mediaAsset;

        if ($asset === null) {
            throw new RuntimeException('لا يوجد ملف لهذا العنصر.');
        }

        // Entitlement is the LESSON's, whichever of its files is asked for: an
        // attachment travels with the item it hangs on, so a second rule here
        // would be a second thing to keep in step with `mayWatch`.
        if ((int) $asset->owner_id !== (int) $lesson->getKey()
            || $asset->owner_type !== Lesson::class) {
            throw new RuntimeException('لا تملك صلاحية لهذا الإجراء.');
        }

        if (! $this->mayWatch($lesson, $viewer)) {
            throw new RuntimeException('لا تملك صلاحية لهذا الإجراء.');
        }

        $this->assertNotWithheld($lesson, $viewer);

        if (! $asset->isPlayable()) {
            // Distinct from "not allowed": the viewer is entitled, the video is
            // simply not ready. The screen says "قيد التجهيز" instead of an error.
            throw new DomainException($asset->status->value);
        }

        return $this->mint($asset, $viewer, $session, $ipHash);
    }

    /**
     * The money check, and it is a SEPARATE axis from entitlement (FR-042).
     *
     * Deliberately not folded into {@see self::mayWatch()}: that method answers
     * "may this person see this at all", and a student who owes is entitled — the
     * item is theirs, their sessions stay open, and the refusal has to say so with
     * a number and a way to pay. A false returned from `mayWatch` would carry
     * none of that, and the screen would show the sentence written for a stranger.
     *
     * It sits after every entitlement route rather than inside one, so an
     * attachment asked for through `issueForAsset` is covered by the same line —
     * classification lives on the LESSON and its files inherit it, which is the
     * rule the entitlement check above already follows.
     *
     * ⚠️ RE-ASKED ON EVERY ISSUE (FR-043). Checked once at enrolment it would be a
     * stale snapshot: a student who was paid up in September gets everything in
     * December while owing. Same reasoning that keeps the enrolment check here.
     *
     * The author needs no exemption and gets none: a teacher holds no balance in
     * their own course, and a balance row that does not exist is not withheld.
     * An explicit membership test here would be a second query for a case the
     * predicate already answers.
     */
    private function assertNotWithheld(Lesson $lesson, User $viewer): void
    {
        // `course_id` is NOT NULL on lessons — spec 006's backfill made it so, on
        // the grounds that a session with no course has no price at all. So the
        // classification is the whole condition.
        if (! $lesson->is_high_value) {
            return;
        }

        $courseId = (int) $lesson->course_id;

        if (! $this->standing->isWithheld($viewer, $courseId)) {
            return;
        }

        $needed = $this->standing->creditsNeededFor($viewer, $courseId);

        // Read past the scope by primary key: the viewer's current workspace is
        // whichever teacher they last visited, and a student studying with three
        // of them would be told their own course does not exist.
        $courseUuid = (string) Course::query()->withoutWorkspaceScope()->whereKey($courseId)->value('uuid');

        throw new AccessWithheldException(
            "هذا الملف موقوف حتى سداد رصيد هذا الكورس. تحتاج {$needed} حصة على الأقل، وتُشترى من صفحة الأرصدة. حصصك المحجوزة ودروسك العادية لا تتأثّر.",
            creditsNeeded: $needed,
            courseUuid: $courseUuid,
        );
    }

    /**
     * Entitlement for one lesson.
     *
     * Four independent routes, in the order that is cheapest to check: a free or
     * preview lesson, ownership of the workspace that produced it, an active
     * enrolment in its course, or — for a lesson published from a live session's
     * recording — a seat in that session.
     *
     * The last one is NOT a special case of enrolment. FR-030 restricts a
     * recording to the people who booked the session, not to the whole cohort,
     * so publishing it as an ordinary lesson without this check would quietly
     * widen access to everyone enrolled in the course.
     */
    public function mayWatch(Lesson $lesson, User $viewer): bool
    {
        if ($lesson->class_session_id !== null) {
            /*
             * A session recording answers to its seat, and the workspace
             * shortcut below is deliberately NOT applied to it.
             *
             * Every enrolled student is a member of their teacher's workspace,
             * so letting membership open a recording would hand the hour to the
             * entire register — including the students who were not in the room
             * and were never charged for it (FR-030). Only someone who can run
             * the workspace's sessions gets in without a seat.
             */
            return $this->bookings->hasBookingForLesson($viewer, (int) $lesson->getKey())
                || $viewer->can(Permissions::SESSIONS_MANAGE);
        }

        // The author's side, and it comes BEFORE the visibility check on purpose:
        // watching back the video you just uploaded to a draft item is what the
        // authoring surface is for. A student is not a workspace member — the only
        // writers of that pivot are `AcceptInvitation` and `CreateWorkspace`, so
        // enrolling does not grant it.
        if ($viewer->workspaces()->where('workspaces.id', $lesson->workspace_id)->exists()) {
            return true;
        }

        // Everyone else needs the item to be published, and its chapter and section
        // with it. This check did not exist: a student enrolled in the course got a
        // playing grant for a draft lesson's video, and `is_free`/`is_preview`
        // below — which open the file to a signed-out visitor — were read before
        // any status at all, so an unfinished free lesson was public.
        if (! $lesson->isVisibleChain()) {
            return false;
        }

        if ($lesson->is_free || $lesson->is_preview) {
            return true;
        }

        return $this->enrollments->hasActiveEnrollment($viewer, (int) $lesson->course_id);
    }

    /**
     * Entitlement for many lessons at once, in a fixed number of queries.
     *
     * Reads the viewer's entitlement once and filters in memory, so issuing
     * grants for a course listing does not scale with its length (SC-011).
     *
     * @param  iterable<Lesson>  $lessons
     * @return array<int, bool> keyed by lesson id
     */
    public function mayWatchMany(iterable $lessons, User $viewer): array
    {
        $lessons = $lessons instanceof EloquentCollection
            ? $lessons
            : new EloquentCollection(is_array($lessons) ? $lessons : iterator_to_array($lessons));

        // Which of these are published, chain and all — ONE query for the list,
        // asked through the same scope the student-facing readers use.
        //
        // Per-row `isVisibleChain()` was the first attempt and `PlaybackGrantTest`
        // rejected it: `loadMissing` on a single model is a round trip per parent,
        // so a fixed-cost method became four queries and then more. Reading the
        // scope also means this cannot drift from `visibleToStudents` — the risk
        // that put the status check in only one of the two entitlement paths to
        // begin with.
        $visibleIds = array_flip(
            Lesson::query()
                ->withoutGlobalScopes()
                ->whereIn('lessons.id', $lessons->modelKeys())
                ->visibleToStudents()
                ->pluck('lessons.id')
                ->all(),
        );

        $courseIds = array_flip($this->enrollments->activeCourseIdsFor($viewer));
        $workspaceIds = array_flip($viewer->workspaces()->pluck('workspaces.id')->all());

        /*
         * The seat lookup is paid for only when a recording is actually in the
         * list. A course of ordinary lessons is the common case, and charging it
         * two extra queries plus a permission load for a rule it never reaches
         * would trade SC-011 for nothing.
         */
        $bookedLessonIds = null;
        $mayManageSessions = null;

        /*
         * And the same for withholding: one read of the whole withheld set, paid
         * for only when a classified item is actually in the list, then filtered
         * in memory (SC-011). Asking the contract per row is an N+1 by
         * construction — which is why the contract forbids it inside a Resource.
         */
        $withheldCourseIds = null;

        $allowed = [];

        foreach ($lessons as $lesson) {
            $lessonId = (int) $lesson->getKey();

            // Same ordering as mayWatch(), and for the same reason: a recording
            // must not be opened by workspace membership.
            if ($lesson->class_session_id !== null) {
                $bookedLessonIds ??= array_flip($this->bookings->bookedLessonIdsFor($viewer));
                $mayManageSessions ??= $viewer->can(Permissions::SESSIONS_MANAGE);

                $allowed[$lessonId] = isset($bookedLessonIds[$lessonId]) || $mayManageSessions;
            } elseif (isset($workspaceIds[$lesson->workspace_id])) {
                // The author, who may watch their own unfinished work.
                $allowed[$lessonId] = true;
            } else {
                // The status chain, in the same order as mayWatch() — including
                // before the free/preview shortcut, which opens a file to a
                // signed-out visitor. The two methods answer one question and a
                // condition present in only one of them is a hole reachable
                // through whichever caller uses the other.
                $allowed[$lessonId] = isset($visibleIds[$lessonId])
                    && ($lesson->is_free || $lesson->is_preview || isset($courseIds[$lesson->course_id]));
            }

            /*
             * The money veto, applied to whatever the branches above decided —
             * NOT inside one of them. A revision recording is both a classified
             * asset and a session recording, and hanging this off the ordinary
             * branch alone would let the highest-value item in the spec's own list
             * («تسجيلات المراجعة») walk straight past it.
             *
             * Authors and session managers come out unscathed without a special
             * case: neither holds a balance in the course, and a balance row that
             * does not exist is not withheld.
             */
            if ($allowed[$lessonId] && $lesson->is_high_value) {
                $withheldCourseIds ??= array_flip($this->standing->withheldCourseIdsFor($viewer));

                $allowed[$lessonId] = ! isset($withheldCourseIds[$lesson->course_id]);
            }
        }

        return $allowed;
    }

    private function mint(
        MediaAsset $asset,
        User $viewer,
        AuthSession $session,
        ?string $ipHash,
    ): PlaybackGrant {
        $ttl = (int) PlatformSettings::get('media.grant_ttl_seconds', 300);

        return PlaybackGrant::query()->create([
            'workspace_id' => $asset->workspace_id,
            'media_asset_id' => $asset->getKey(),
            'user_id' => $viewer->getKey(),
            // The binding that makes a copied link useless: when this session
            // ends, every grant it minted dies with it.
            'auth_session_id' => $session->getKey(),
            'expires_at' => now()->addSeconds($ttl),
            'issued_ip_hash' => $ipHash,
            'created_at' => now(),
        ]);
    }
}
