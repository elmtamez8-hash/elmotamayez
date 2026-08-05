<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

trait MediaFixtures
{
    /**
     * A lesson with a ready video, and real bytes on the fake disk.
     *
     * The bytes matter: the guard is exercised through actual range requests, so
     * an asset pointing at nothing would pass the guard and then 404 for the
     * wrong reason.
     */
    protected function lessonWithVideo(Workspace $workspace, ?Course $course = null, int $bytes = 4096): Lesson
    {
        Storage::fake('local');

        return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $course, $bytes): Lesson {
            $course ??= Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

            $lesson = Lesson::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'type' => 'video',
                'is_free' => false,
                'is_preview' => false,
            ]);

            $asset = MediaAsset::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'owner_type' => Lesson::class,
                'owner_id' => $lesson->getKey(),
                'status' => MediaAssetStatus::Ready,
            ]);

            Storage::disk('local')->put((string) $asset->provider_asset_id, str_repeat('v', $bytes));

            return $lesson->refresh();
        });
    }

    /**
     * A student enrolled in the lesson's course, signed in, with a live session.
     *
     * @return array{0: User, 1: AuthSession}
     */
    protected function enrolledViewer(Workspace $workspace, Lesson $lesson, ?User $student = null): array
    {
        $student ??= User::factory()->create(['platform_role' => PlatformRole::Student]);

        app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $lesson, $student): void {
            Enrollment::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $lesson->course_id,
                'student_user_id' => $student->getKey(),
                'status' => 'active',
            ]);
        });

        Sanctum::actingAs($student);
        $this->asGuest();

        $session = $this->sessionFor($student);
        $session->forceFill(['token_id' => $student->currentAccessToken()->getKey()])->save();

        return [$student, $session];
    }

    /** A signed-in session for this user, which grants bind to. */
    protected function sessionFor(User $user): AuthSession
    {
        $device = Device::factory()->create(['user_id' => $user->getKey()]);

        return AuthSession::factory()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
        ]);
    }
}
