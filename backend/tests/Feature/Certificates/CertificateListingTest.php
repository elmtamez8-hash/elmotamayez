<?php

declare(strict_types=1);

use App\Modules\Certificates\Actions\IssueCertificate;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function createCertificate(int $workspaceId, int $studentId): Certificate
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspaceId, 'is_sequential' => false]);

    $enrollment = Enrollment::create([
        'workspace_id' => $workspaceId, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $studentId, 'status' => 'completed', 'enrolled_at' => now(),
        'completed_at' => now(),
    ]);

    return app(IssueCertificate::class)->handle($enrollment, 'course_completed');
}

describe('certificate listing', function (): void {
    it('lists own certificates for a student', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $cert = createCertificate($workspace->id, $student->id);

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.certificate_number', $cert->certificate_number);
    });

    it('lists all certificates for staff with view-all', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $teacher = $this->addWorkspaceMember($workspace, 'teacher');
        createCertificate($workspace->id, $student->id);
        createCertificate($workspace->id, $teacher->id);

        Sanctum::actingAs($teacher);

        $this->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('sends the pagination a second page depends on', function (): void {
        /*
        | ⚠️ THIS IS THE ASSERTION THAT WAS MISSING FOR THE LIFE OF THE SCREEN.
        | `response()->json(Resource::collection($paginator))` never calls
        | `toResponse()`, so `meta` was dropped in silence and
        | `manage/certificates/page.tsx` read `meta.last_page` as `1` for ever —
        | «عرض المزيد» never appeared for a teacher with more than fifteen
        | certificates, and every other test here was green over the broken shape
        | because it only ever created one or two rows (SC-010 · `FR-038`).
        */
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');

        foreach (range(1, 16) as $ignored) {
            createCertificate($workspace->id, $student->id);
        }

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 16);
    });

    it('shows a single certificate', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $cert = createCertificate($workspace->id, $student->id);

        Sanctum::actingAs($student);

        $this->getJson("/api/v1/certificates/{$cert->uuid}")
            ->assertOk()
            ->assertJsonPath('certificate_number', $cert->certificate_number);
    });
});

describe('certificate regeneration', function (): void {
    it('allows a teacher to regenerate a certificate', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $teacher = $this->addWorkspaceMember($workspace, 'teacher');
        $cert = createCertificate($workspace->id, $student->id);

        Sanctum::actingAs($teacher);

        $this->postJson("/api/v1/certificates/{$cert->uuid}/regenerate")
            ->assertOk()
            ->assertJsonPath('certificate_number', $cert->certificate_number);

        expect(Certificate::where('id', $cert->id)->exists())->toBeTrue();
    });

    it('sends a regenerated notification (not the issued one) on regeneration', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $teacher = $this->addWorkspaceMember($workspace, 'teacher');
        $cert = createCertificate($workspace->id, $student->id);

        Sanctum::actingAs($teacher);

        $this->postJson("/api/v1/certificates/{$cert->uuid}/regenerate")->assertOk();

        // `type` is a domain key now, not a PHP class name (spec 003).
        $notifications = $student->notifications()
            ->where('type', NotificationType::CertificateRegenerated->value)
            ->get();

        expect($notifications)->toHaveCount(1)
            ->and($notifications->first()->payload['certificate_number'])->toBe($cert->certificate_number);
    });

    it('denies regeneration to students', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $cert = createCertificate($workspace->id, $student->id);

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/certificates/{$cert->uuid}/regenerate")->assertForbidden();
    });
});
