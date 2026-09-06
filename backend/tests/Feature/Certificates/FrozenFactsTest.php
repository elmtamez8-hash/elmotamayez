<?php

declare(strict_types=1);

use App\Modules\Certificates\Models\Certificate;
use App\Modules\Marketplace\Models\Subject;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CertificateFixtures;

describe('the facts a certificate freezes at issue', function (): void {
    it('writes the teacher and the subject at issue', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $certificate = CertificateFixtures::issue($workspace->id, $student->id, $owner->id);

        expect($certificate->teacher_display_name)->toBe($owner->name)
            ->and($certificate->subject_display_name)->not->toBeNull();
    });

    it('does not follow a teacher who renames themselves afterwards', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $certificate = CertificateFixtures::issue($workspace->id, $student->id, $owner->id);
        $frozen = $certificate->teacher_display_name;

        $owner->update(['first_name' => 'Renamed', 'last_name' => 'Afterwards']);

        $this->asGuest();

        $this->getJson("/api/v1/certificates/verify/{$certificate->verification_code}")
            ->assertOk()
            ->assertJsonPath('certificate.teacher_name', $frozen);

        expect($frozen)->not->toBe($owner->fresh()->name);
    });

    it('does not follow a course re-filed under another subject', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $certificate = CertificateFixtures::issue($workspace->id, $student->id, $owner->id);
        $frozen = $certificate->subject_display_name;

        $other = Subject::create(['slug' => 'astro-'.uniqid(), 'name_ar' => 'Astronomy', 'is_active' => true]);
        $certificate->course->update(['subject_id' => $other->getKey()]);

        $this->asGuest();

        $this->getJson("/api/v1/certificates/verify/{$certificate->verification_code}")
            ->assertOk()
            ->assertJsonPath('certificate.subject_name', $frozen);
    });

    /*
    | FR-010. Moving issued_at was inert while nothing printed the date, and it
    | becomes unintentional forgery the moment the certificate carries it.
    */
    it('does not move the grant date when the certificate is regenerated', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $teacher = $this->addWorkspaceMember($workspace, 'teacher');
        $certificate = CertificateFixtures::issue($workspace->id, $student->id, $owner->id);

        Certificate::withoutWorkspaceScope()
            ->where('id', $certificate->getKey())
            ->update(['issued_at' => now()->subYear()]);

        $issuedAt = Certificate::withoutWorkspaceScope()->find($certificate->getKey())->issued_at;

        Sanctum::actingAs($teacher);
        $this->postJson("/api/v1/certificates/{$certificate->uuid}/regenerate")->assertOk();

        expect(
            Certificate::withoutWorkspaceScope()->find($certificate->getKey())->issued_at->toIso8601String()
        )->toBe($issuedAt->toIso8601String());
    });
});
