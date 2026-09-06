<?php

declare(strict_types=1);

use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use Tests\Support\CertificateFixtures;

describe('the public verify payload', function (): void {
    it('carries the six facts and the design', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $certificate = CertificateFixtures::issue($workspace->id, $student->id, $owner->id);

        $this->asGuest();

        $response = $this->getJson("/api/v1/certificates/verify/{$certificate->verification_code}")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('certificate.student_name', $student->name)
            ->assertJsonPath('certificate.teacher_name', $owner->name)
            ->assertJsonPath(
                'certificate.design.image_url',
                CertificateTemplateRegistry::default()['image_url'],
            );

        expect($response->json('certificate.subject_name'))->not->toBeNull()
            ->and($response->json('certificate.certificate_number'))->toBe($certificate->certificate_number)
            ->and(array_keys($response->json('certificate.design.boxes')))
            ->toEqualCanonicalizing(CertificateTemplateRegistry::FIELDS);
    });

    /*
    | FR-032 - SC-008. An allowlist, asserted as an EXACT set: a "does not
    | contain an email" assertion is true of a payload that leaks something else,
    | and this route answers a stranger who holds nothing but a code.
    */
    it('carries no field outside the named list', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $certificate = CertificateFixtures::issue($workspace->id, $student->id, $owner->id);

        $this->asGuest();

        $payload = $this->getJson("/api/v1/certificates/verify/{$certificate->verification_code}")
            ->assertOk()
            ->json('certificate');

        expect(array_keys($payload))->toEqualCanonicalizing([
            'certificate_number',
            'verification_code',
            'issue_reason',
            'issued_at',
            'course_title',
            'student_name',
            'teacher_name',
            'subject_name',
            'design',
        ]);
    });

    it('refuses an unknown code with no name and no design', function (): void {
        $this->asGuest();

        $response = $this->getJson('/api/v1/certificates/verify/definitely-not-a-code')
            ->assertNotFound()
            ->assertJsonPath('valid', false);

        expect($response->json('certificate'))->toBeNull()
            ->and($response->json('design'))->toBeNull();
    });
});
