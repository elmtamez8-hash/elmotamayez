<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Models\Order;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| A teacher asking for another workspace's exam, homework, certificate or order
| by its uuid used to get 403 — while a random uuid got 404. The difference told
| them the row exists. Both now answer 404, the way a course already does.
*/

beforeEach(function (): void {
    [$this->theirs, $this->theirOwner] = $this->createWorkspaceWithOwner();
    [, $this->prober] = $this->createWorkspaceWithOwner();

    $course = Course::factory()->published()->create([
        'workspace_id' => $this->theirs->getKey(),
        'created_by' => $this->theirOwner->getKey(),
    ]);
    $student = User::factory()->create();
    $enrollment = $this->createEnrollment($this->theirs, $course, $student);

    $this->urls = [
        'exam' => '/api/v1/exams/'.Exam::create([
            'workspace_id' => $this->theirs->getKey(), 'uuid' => Str::uuid(),
            'title' => 'Final', 'status' => 'draft',
        ])->uuid,
        'assignment' => '/api/v1/assignments/'.Assignment::factory()->create([
            'workspace_id' => $this->theirs->getKey(), 'course_id' => $course->getKey(),
        ])->uuid,
        'certificate' => '/api/v1/certificates/'.Certificate::create([
            'workspace_id' => $this->theirs->getKey(), 'certificate_number' => 'C-X1', 'verification_code' => 'VX1',
            'enrollment_id' => $enrollment->getKey(), 'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(), 'student_display_name' => 'S',
            'teacher_display_name' => 'T', 'issue_reason' => 'course_completed', 'issued_at' => now(),
        ])->uuid,
        'order' => '/api/v1/orders/'.Order::query()->create([
            'workspace_id' => $this->theirs->getKey(), 'user_id' => $student->getKey(),
            'amount_minor' => 1000, 'currency' => 'EGP', 'status' => 'pending',
        ])->uuid,
    ];
});

function getAs(User $user, string $url): TestResponse
{
    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs($user);

    return test()->getJson($url);
}

it('answers a real uuid from another workspace exactly like a random one', function (string $door): void {
    $url = $this->urls[$door];

    getAs($this->prober, $url)->assertNotFound();
    getAs($this->prober, preg_replace('/[0-9a-f-]{36}$/', (string) Str::uuid(), $url))->assertNotFound();
})->with(['exam', 'assignment', 'certificate', 'order']);

it('still opens the row to its own workspace', function (string $door): void {
    getAs($this->theirOwner, $this->urls[$door])->assertOk();
})->with(['exam', 'assignment', 'certificate', 'order']);
