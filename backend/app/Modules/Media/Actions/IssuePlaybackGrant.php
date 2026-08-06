<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;
use DomainException;
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
    ): PlaybackGrant {
        $asset = $lesson->mediaAsset;

        if ($asset === null) {
            throw new RuntimeException('لا يوجد فيديو لهذا الدرس.');
        }

        if (! $this->mayWatch($lesson, $viewer)) {
            throw new RuntimeException('لا تملك صلاحية لهذا الإجراء.');
        }

        if (! $asset->isPlayable()) {
            // Distinct from "not allowed": the viewer is entitled, the video is
            // simply not ready. The screen says "قيد التجهيز" instead of an error.
            throw new DomainException($asset->status->value);
        }

        return $this->mint($asset, $viewer, $session, $ipHash);
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
        if ($lesson->is_free || $lesson->is_preview) {
            return true;
        }

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

        if ($viewer->workspaces()->where('workspaces.id', $lesson->workspace_id)->exists()) {
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

        $allowed = [];

        foreach ($lessons as $lesson) {
            $lessonId = (int) $lesson->getKey();

            if ($lesson->is_free || $lesson->is_preview) {
                $allowed[$lessonId] = true;

                continue;
            }

            // Same ordering as mayWatch(), and for the same reason: a recording
            // must not be opened by workspace membership.
            if ($lesson->class_session_id !== null) {
                $bookedLessonIds ??= array_flip($this->bookings->bookedLessonIdsFor($viewer));
                $mayManageSessions ??= $viewer->can(Permissions::SESSIONS_MANAGE);

                $allowed[$lessonId] = isset($bookedLessonIds[$lessonId]) || $mayManageSessions;

                continue;
            }

            $allowed[$lessonId] = isset($workspaceIds[$lesson->workspace_id])
                || isset($courseIds[$lesson->course_id]);
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
