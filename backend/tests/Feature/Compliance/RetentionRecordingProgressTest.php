<?php

declare(strict_types=1);

use App\Modules\Certificates\Models\Certificate;
use App\Modules\Compliance\Jobs\RunRetentionSweepJob;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * SC-016 — nobody is left below 100% by a recording that expired.
 *
 * ⚠️ A TEST THAT ONLY CHECKED THE ASSET WAS DELETED WOULD WALK PAST THE DEFECT.
 * The thing that breaks here is not the file — it is the LESSON the file became.
 * An item that sits in the progress denominator and can never be completed caps
 * every enrolled student below 100% permanently: `CourseCompleted` never fires
 * and no certificate ever issues, with nothing logged. 016 closed six roads to
 * that failure and `FR-031ب` names retention as the seventh, so what this file
 * measures is the PERCENTAGE and the CERTIFICATE, on the far side of the sweep.
 *
 * ⚠️ AND IT IS ALSO THE GUARD ON THE EXCLUSION THAT MAKES IT TRUE. A recording is
 * entitled by a SEAT rather than by enrolment, so `progressEligible()` drops any
 * lesson carrying a `class_session_id` — which is why expiring one cannot move a
 * denominator today. Make recordings countable tomorrow and this file is what
 * fails, rather than a student who finds out in three years.
 */
function retentionCourseWithTwoRecordings(int $workspaceId): array
{
    $course = Course::factory()->published()->create([
        'workspace_id' => $workspaceId,
        'is_sequential' => true,
    ]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $make = function (string $title, int $order, ?int $sessionId = null) use ($workspaceId, $course, $section, $chapter): Lesson {
        return Lesson::create([
            'workspace_id' => $workspaceId, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => $title, 'type' => 'article',
            'status' => ContentStatus::Published, 'content' => 'x',
            'class_session_id' => $sessionId, 'order' => $order,
        ]);
    };

    $profile = TeacherProfile::factory()->create(['workspace_id' => $workspaceId]);

    $recordings = [];

    /*
    | ⚠️ TWO RECORDINGS IN ONE COURSE, WHICH IS WHAT MAKES T132 MEASURABLE. One
    | would pass against a listener that fires `CourseStructureChanged` per LESSON
    | — and fifty recordings ageing out on one night would then run fifty full
    | progress resyncs over one identical set of enrolments, which is what actually
    | threatens the sweep's timeout.
    */
    foreach ([1, 2] as $index) {
        $session = ClassSession::factory()->create([
            'workspace_id' => $workspaceId,
            'teacher_profile_id' => $profile->id,
            'course_id' => $course->id,
        ]);

        $recordings[] = $make("تسجيل الحصة {$index}", $index, (int) $session->getKey());

        $asset = MediaAsset::query()->create([
            'workspace_id' => $workspaceId,
            'owner_type' => ClassSession::class,
            'owner_id' => $session->getKey(),
            'provider' => 'local',
            'provider_asset_id' => "vid-{$index}",
            'kind' => MediaKind::Video,
            'role' => MediaRole::Primary,
            'status' => 'ready',
            'original_filename' => "session-{$index}.mp4",
        ]);

        // Aged by query: `created_at` is not fillable, so passing it to `create()`
        // is discarded in silence and the row is born today — too young to sweep.
        MediaAsset::query()->withoutWorkspaceScope()
            ->whereKey($asset->getKey())
            ->update(['created_at' => now()->subDays(900)]);
    }

    $countable = $make('الدرس الوحيد المحسوب', 0);

    return [$course, $countable, $recordings];
}

beforeEach(function (): void {
    $this->mock(LocalMediaProvider::class, function ($mock): void {
        $mock->shouldReceive('identifier')->andReturn('local');
        $mock->shouldReceive('delete');
    });
});

it('keeps a finished student at 100% with their certificate after the recordings expire', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$course, $countable, $recordings] = retentionCourseWithTwoRecordings($workspace->id);

    $student = $this->addWorkspaceMember($workspace, 'student');
    Sanctum::actingAs($student);

    $enrollment = Enrollment::create([
        'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
    ]);

    // Everything countable, done. The two recordings are not countable — the
    // student never held a seat in either room.
    $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$countable->uuid}/complete")->assertOk();

    expect((float) $enrollment->refresh()->progress_pct)->toBe(100.0)
        ->and(Certificate::query()->withoutWorkspaceScope()
            ->where('student_user_id', $student->id)->count())->toBe(1);

    RunRetentionSweepJob::dispatchSync();

    // The lessons left the tree…
    expect(Lesson::query()->withoutWorkspaceScope()
        ->whereIn('id', collect($recordings)->pluck('id'))
        ->where('status', ContentStatus::Archived->value)
        ->count())->toBe(2)
        // …and the student is exactly where they were: finished, and holding it.
        ->and((float) $enrollment->refresh()->progress_pct)->toBe(100.0)
        ->and(Certificate::query()->withoutWorkspaceScope()
            ->where('student_user_id', $student->id)->count())->toBe(1);
});

/*
 * ⚠️ FR-031ب · T132 — ONCE PER COURSE, NOT ONCE PER RECORDING.
 *
 * `ResyncCourseProgress` walks every enrolment of the course it is handed, so an
 * event fired per expired lesson multiplies that walk by however many recordings
 * a course happens to age out on one night. The dedup has to live where the
 * courses are known — in the listener, not at the call site, which is why `Media`
 * announces a BATCH rather than an asset.
 */
it('announces the course once however many of its recordings expired', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    retentionCourseWithTwoRecordings($workspace->id);

    Event::fake([CourseStructureChanged::class]);

    RunRetentionSweepJob::dispatchSync();

    Event::assertDispatchedTimes(CourseStructureChanged::class, 1);
});
