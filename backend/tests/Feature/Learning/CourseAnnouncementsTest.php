<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\Announcement;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\LessonAccess;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| `GET /courses/{course}/announcements` — the tab (US2 · FR-019).
|
| ⚠️ A READ THAT DID NOT EXIST. `/manage/announcements` is the teacher's, and the
| student's whole surface has been the notification bell: `lib/announcements.ts`
| said so in as many words, and the announcement body travels inside the
| notification precisely because there was no screen to link to.
|
| ⚠️ THE EXPLICIT `where` IS THE ENTIRE GUARD. The reader is a student, so
| `users.last_workspace_id` is NULL, `WorkspaceContext::id()` is null, and
| `WorkspaceScope::apply()` adds NO condition at all — `Announcement::query()`
| here is as exposed as an unauthenticated one. Which is why the fixture puts a
| published announcement in a SECOND WORKSPACE: a sibling course in the same
| workspace would pass against a build whose only filter is the workspace.
*/

/** @return array{workspace: Workspace, student: User, course: Course, other: Course, foreign: Course} */
function announcementFixture(): array
{
    /** @var TestCase $test */
    $test = test();

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $student = User::factory()->create();

    $post = function (Workspace $workspace, User $author, string $scope, ?int $scopeId, string $body, bool $live = true): Announcement {
        $announcement = Announcement::create([
            'workspace_id' => $workspace->getKey(),
            'author_user_id' => $author->getKey(),
            'scope' => $scope,
            'body' => $body,
            'is_urgent' => false,
        ]);

        // `published_at` and `scope_id` are not fillable — both are claimed by the
        // Action rather than mass-assigned, which is the whole reason they are not
        // in `$fillable`. A fixture writes them the same way the Action does.
        $announcement->forceFill([
            'scope_id' => $scopeId,
            'published_at' => $live ? now() : null,
        ])->save();

        return $announcement;
    };

    $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student, $post): array {
        $make = fn (string $title): Course => Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'title' => $title,
        ]);

        $course = $make('الرياضيات');
        $other = $make('الفيزياء');

        Enrollment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);

        $post($workspace, $owner, Announcement::SCOPE_COURSE, (int) $course->getKey(), 'حصّةُ الغدِ في السابعة.');
        $post($workspace, $owner, Announcement::SCOPE_COURSE, (int) $other->getKey(), 'كلامٌ عن الفيزياء.');

        // Written but never published — a draft is the teacher's working copy.
        $post($workspace, $owner, Announcement::SCOPE_COURSE, (int) $course->getKey(), 'مسوّدةٌ لم تُنشَر.', live: false);

        // Published and then withdrawn. `hidden_at` rather than a soft delete, so
        // the moderation screen can still read what it acted on — which is
        // exactly why the student's query has to exclude it by hand.
        $post($workspace, $owner, Announcement::SCOPE_COURSE, (int) $course->getKey(), 'ألغِ ما قلتُه.')
            ->forceFill(['hidden_at' => now()])->save();

        // Workspace-wide: reaches this student through the bell, and is not «this
        // course's announcement». Repeated under every course tab it is noise.
        $post($workspace, $owner, Announcement::SCOPE_ALL, null, 'إجازةٌ الأسبوعَ القادم.');

        return compact('course', 'other');
    });

    [$foreignWorkspace, $foreignOwner] = $test->createWorkspaceWithOwner();

    $foreign = app(WorkspaceContext::class)->forWorkspace($foreignWorkspace, function () use ($foreignWorkspace, $foreignOwner, $post): Course {
        $foreign = Course::factory()->published()->create([
            'workspace_id' => $foreignWorkspace->getKey(),
            'created_by' => $foreignOwner->getKey(),
            'title' => 'كورسُ مدرّسٍ آخر',
        ]);

        $post($foreignWorkspace, $foreignOwner, Announcement::SCOPE_COURSE, (int) $foreign->getKey(), 'كلامُ مدرّسٍ آخر.');

        return $foreign;
    });

    return compact('workspace', 'student', 'foreign') + $built;
}

beforeEach(function (): void {
    $this->fx = announcementFixture();

    Sanctum::actingAs($this->fx['student']);
    $this->asGuest();
});

it('gives the student this course\'s live announcements and nothing else', function (): void {
    $bodies = collect(
        $this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/announcements')
            ->assertOk()
            ->json('data'),
    )->pluck('body')->all();

    expect($bodies)->toBe(['حصّةُ الغدِ في السابعة.'])
        ->and($bodies)->not->toContain('كلامٌ عن الفيزياء.')
        ->and($bodies)->not->toContain('مسوّدةٌ لم تُنشَر.')
        ->and($bodies)->not->toContain('ألغِ ما قلتُه.')
        ->and($bodies)->not->toContain('إجازةٌ الأسبوعَ القادم.');
});

/*
| ⚠️ ASKED FOR BY UUID, WHICH IS THE ONLY WAY IN. A course in another teacher's
| workspace is the case a workspace filter alone would catch and an ownership
| filter alone would not — and the reader here has no workspace context at all,
| so nothing but the enrolment check stands between them and it.
*/
it('refuses another teacher\'s course with 403 rather than an empty list', function (): void {
    $this->getJson('/api/v1/courses/'.$this->fx['foreign']->uuid.'/announcements')
        ->assertForbidden()
        ->assertJsonPath('code', LessonAccess::NOT_ENROLLED);
});

it('refuses a course in the same workspace the reader is not enrolled in', function (): void {
    $this->getJson('/api/v1/courses/'.$this->fx['other']->uuid.'/announcements')
        ->assertForbidden()
        ->assertJsonPath('code', LessonAccess::NOT_ENROLLED);
});

it('does not carry the teacher\'s counters to the student', function (): void {
    $row = $this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/announcements')
        ->assertOk()
        ->json('data.0');

    // `notified_count` and `read_count` answer «how many did I reach» — the
    // publisher's question, and a headcount of the class handed to a member of
    // it. The student's payload is a different object, not a redacted one.
    expect($row)->not->toHaveKey('notified_count')
        ->and($row)->not->toHaveKey('read_count')
        ->and($row)->toHaveKey('author_name')
        ->and($row)->toHaveKey('is_urgent');
});
