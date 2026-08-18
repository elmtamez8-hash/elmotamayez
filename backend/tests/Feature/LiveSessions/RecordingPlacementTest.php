<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-020 · SC-014 · FR-047أ — the recording lands WHERE THE TEACHER PUT THE
| SESSION, and one session produces one item.
|
| Before 016 the listener always appended to a "تسجيلات الحصص" chapter at the end
| of the course. That is still right for a session nobody placed. But a teacher
| who put "الحصة الثالثة" between two lessons of their sequence now gets two rows
| for one hour: a placeholder in the middle and a recording at the end — and in a
| sequential course the placeholder stands in front of everything after it
| forever, because nothing can ever complete it.
|
| The lookup order inside the listener is what these tests are really about, and
| the last one is the reason the order is written down: idempotence FIRST, item
| conversion second.
*/

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    Http::fake(['*' => Http::response(placementMp4Bytes(), 200)]);

    $this->provider = new FakeBroadcastProvider;
    $this->provider->pendingRecording = new RecordingArtifact(
        downloadUrl: 'https://fake.test/recordings/1.mp4',
        sizeBytes: 1024,
        durationSeconds: 3600,
        mimeType: 'video/mp4',
    );
    $this->app->instance(BroadcastProviderInterface::class, $this->provider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->section = Section::create([
        'workspace_id' => $this->workspace->getKey(), 'course_id' => $this->course->getKey(),
        'title' => 'الوحدة الأولى', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $this->chapter = Chapter::create([
        'workspace_id' => $this->workspace->getKey(), 'section_id' => $this->section->getKey(),
        'course_id' => $this->course->getKey(), 'title' => 'الفصل الأول',
        'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'title' => 'الحصة الثالثة',
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);
});

/** The smallest byte sequence finfo recognises as video/mp4. */
function placementMp4Bytes(): string
{
    return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 512);
}

function placeSessionItem(int $order = 2, ?int $referenceId = null): Lesson
{
    return Lesson::create([
        'workspace_id' => test()->workspace->getKey(),
        'course_id' => test()->course->getKey(),
        'section_id' => test()->section->getKey(),
        'chapter_id' => test()->chapter->getKey(),
        'uuid' => Str::uuid(),
        'title' => 'الحصة الثالثة: المشتقات',
        'type' => 'live_session',
        'status' => ContentStatus::Published,
        'order' => $order,
        'reference_id' => $referenceId ?? test()->session->getKey(),
    ]);
}

function ingestRecording(): void
{
    // Resolved through the container rather than listed by hand: the job's
    // dependencies are its own business, and it grew one the day the broadcast
    // provider had to be read from the SESSION rather than from the config.
    app()->call([
        new IngestSessionRecordingJob((int) test()->session->getKey()),
        'handle',
    ]);
}

it('converts the placed item in place instead of appending a second row', function (): void {
    $item = placeSessionItem();

    ingestRecording();

    $lessons = Lesson::query()->withoutWorkspaceScope()
        ->where('course_id', $this->course->getKey())->get();

    // ONE row for one session (SC-020) — the same row, in the same position.
    expect($lessons)->toHaveCount(1);

    $converted = $lessons->first();

    expect($converted->getKey())->toBe($item->getKey())
        ->and($converted->uuid)->toBe($item->uuid)
        ->and($converted->type)->toBe('video')
        ->and($converted->chapter_id)->toBe($this->chapter->getKey())
        ->and($converted->order)->toBe(2)
        ->and($converted->class_session_id)->toBe($this->session->getKey())
        // No longer a placeholder for a session, so it must stop being judged as
        // one: left set, the reference filter would keep checking it against
        // `class_sessions`.
        ->and($converted->reference_id)->toBeNull()
        // SC-014: it arrives visible. A recording that lands as a draft is a
        // recording nobody can watch.
        ->and($converted->status)->toBe(ContentStatus::Published)
        // The teacher named this row inside their own sequence. A background job
        // renaming it to "تسجيل: …" is the job editing their course.
        ->and($converted->title)->toBe('الحصة الثالثة: المشتقات');

    // No recordings chapter was invented, because nothing needed one.
    expect(Section::query()->withoutWorkspaceScope()
        ->where('course_id', $this->course->getKey())->count())->toBe(1);
});

it('still appends to the recordings chapter when no item was placed', function (): void {
    ingestRecording();

    $lesson = Lesson::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())->firstOrFail();

    // 005's behaviour, unchanged. A session nobody placed has no position to
    // land in, and inventing one in the middle of a course would be worse than
    // the end of it.
    expect($lesson->chapter_id)->not->toBe($this->chapter->getKey())
        ->and($lesson->title)->toBe('تسجيل: الحصة الثالثة')
        ->and($lesson->status)->toBe(ContentStatus::Published);
});

it('updates the item it already produced rather than converting a second one', function (): void {
    // The order inside the listener, tested directly. A recording appended
    // before the teacher created an item, then an item created afterwards: if
    // the item lookup ran first, the re-ingest would convert it too and the
    // course would end up with both rows for one session.
    ingestRecording();
    $appended = Lesson::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())->firstOrFail();

    $late = placeSessionItem(order: 5);

    $this->session->forceFill(['recording_status' => 'pending', 'recording_attempts' => 0])->save();
    ingestRecording();

    $recordings = Lesson::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())->get();

    expect($recordings)->toHaveCount(1)
        ->and($recordings->first()->getKey())->toBe($appended->getKey())
        // The late item is untouched — still a placeholder, still the teacher's
        // to remove. What must NOT happen is a second recording row.
        ->and($late->refresh()->type)->toBe('live_session');
});
