<?php

declare(strict_types=1);

use App\Modules\Certificates\Models\CertificateDesign;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CertificateFixtures;

/*
| ⚠️ THE ALLOW DIRECTION IS THE ONE THAT PROVES THE POLICY RUNS.
|
| A file that only asserts "a student gets 403" passes just as well against a
| build where Gate::policy() was never called: Laravel's policy resolution fails
| OPEN into "no policy applies", and a student would be refused by some other
| condition anyway. It is the teacher who CAN adopt that fails if this feature is
| wired to nothing - the taxonomy.manage lesson, reached from the allow side.
*/
describe('choosing a certificate design', function (): void {
    beforeEach(function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'Academy']);

        $this->workspace = $workspace;
        $this->teacher = $owner;
        $this->student = $this->addWorkspaceMember($workspace, 'student');
    });

    it('lists every shipped template, adopted or not', function (): void {
        Sanctum::actingAs($this->teacher);
        $this->setCurrentWorkspace($this->workspace, $this->teacher);

        $response = $this->getJson('/api/v1/certificate-designs')->assertOk();

        // Both shipped designs are offered even though this workspace owns no row.
        expect($response->json('data'))->toHaveCount(2);
        expect(collect($response->json('data'))->pluck('system_key')->all())
            ->toEqualCanonicalizing(['classic', 'students']);

        // ⚠️ `uuid: null` is what says "adopt me" - a shipped template a workspace
        // has not taken has no row and therefore no identifier.
        expect($response->json('data.0.uuid'))->toBeNull();
        expect($response->json('data.0.is_ready'))->toBeTrue();
        expect($response->json('data.0.is_selected'))->toBeFalse();
        expect($response->json('upload_limit'))->toBeInt();
        expect($response->json('uploads_used'))->toBe(0);
    });

    it('adopts and selects in the same act', function (): void {
        Sanctum::actingAs($this->teacher);
        $this->setCurrentWorkspace($this->workspace, $this->teacher);

        $this->postJson('/api/v1/certificate-designs', ['system_key' => 'students'])
            ->assertCreated()
            ->assertJsonPath('system_key', 'students')
            ->assertJsonPath('is_selected', true)
            ->assertJsonPath('image_url', '/certificate-templates/students.webp');

        $design = CertificateDesign::withoutWorkspaceScope()->firstOrFail();

        expect($design->selected_for_workspace_id)->toBe($this->workspace->getKey());
    });

    it('clears the previous selection so a workspace holds exactly one', function (): void {
        Sanctum::actingAs($this->teacher);
        $this->setCurrentWorkspace($this->workspace, $this->teacher);

        $this->postJson('/api/v1/certificate-designs', ['system_key' => 'students'])->assertCreated();
        $this->postJson('/api/v1/certificate-designs', ['system_key' => 'classic'])->assertCreated();

        $selected = CertificateDesign::withoutWorkspaceScope()
            ->whereNotNull('selected_for_workspace_id')
            ->get();

        expect($selected)->toHaveCount(1);
        expect($selected->first()->system_key)->toBe('classic');
    });

    it('creates no twin when the same template is adopted twice', function (): void {
        Sanctum::actingAs($this->teacher);
        $this->setCurrentWorkspace($this->workspace, $this->teacher);

        // ⚠️ There is no unique index on (workspace_id, system_key). Two taps or a
        // retried request would otherwise own two rows for one template, and the
        // gallery merges the registry BY KEY - so the second would appear as a
        // duplicate card whose selection state disagrees with its twin.
        $this->postJson('/api/v1/certificate-designs', ['system_key' => 'classic'])->assertCreated();
        $this->postJson('/api/v1/certificate-designs', ['system_key' => 'classic'])->assertCreated();

        expect(CertificateDesign::withoutWorkspaceScope()->count())->toBe(1);
        expect($this->getJson('/api/v1/certificate-designs')->json('data'))->toHaveCount(2);
    });

    it('refuses a student at the door', function (): void {
        Sanctum::actingAs($this->student);
        $this->setCurrentWorkspace($this->workspace, $this->student);

        $this->getJson('/api/v1/certificate-designs')->assertForbidden();
        $this->postJson('/api/v1/certificate-designs', ['system_key' => 'classic'])->assertForbidden();
    });

    it('refuses a key the registry does not carry', function (): void {
        Sanctum::actingAs($this->teacher);
        $this->setCurrentWorkspace($this->workspace, $this->teacher);

        $this->postJson('/api/v1/certificate-designs', ['system_key' => 'gilded'])
            ->assertStatus(422);
    });

    it('redraws a certificate already issued, with no reissue', function (): void {
        $certificate = CertificateFixtures::issue(
            $this->workspace->id,
            $this->student->id,
            $this->teacher->id,
        );

        $issuedAt = $certificate->issued_at;

        Sanctum::actingAs($this->teacher);
        $this->setCurrentWorkspace($this->workspace, $this->teacher);
        $this->postJson('/api/v1/certificate-designs', ['system_key' => 'students'])->assertCreated();

        // The design is read LIVE while the six facts are frozen at issue: nothing
        // is reissued, the date does not move, and a certificate already printed
        // still verifies (FR-014 - SC-004).
        $this->asGuest();

        $this->getJson("/api/v1/certificates/verify/{$certificate->verification_code}")
            ->assertOk()
            ->assertJsonPath('certificate.design.image_url', '/certificate-templates/students.webp')
            ->assertJsonPath('certificate.student_name', $certificate->student_display_name);

        expect($certificate->fresh()->issued_at->equalTo($issuedAt))->toBeTrue();
    });
});
