<?php

declare(strict_types=1);

use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CertificateFixtures;

/*
| THE VICTIM IS NOT THE VISITOR, AND A GUEST-ONLY TEST PROVES NOTHING.
|
| WorkspaceScope::apply() adds no condition when the context resolves to null,
| which it always does for a guest - so the guest case below is green against a
| build with no withoutWorkspaceScope() anywhere in it. What breaks is a
| SIGNED-IN TEACHER FROM ANOTHER WORKSPACE following the link: their context
| resolves to their own workspace, the scope bites, and the certificate is drawn
| on their design instead of the owner design, or on nothing at all.
|
| Without this file nothing prevents the defect coming back.
*/
describe('reading a certificate design from outside its workspace', function (): void {
    beforeEach(function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $student = $this->addWorkspaceMember($workspaceA, 'student');
        $this->certificate = CertificateFixtures::issue($workspaceA->id, $student->id, $ownerA->id);

        // Workspace A picked the NON-default design; workspace B picked nothing.
        // A default on both sides would make the two answers identical and the
        // assertion vacuous.
        $design = app(WorkspaceContext::class)->forWorkspace(
            $workspaceA,
            fn () => CertificateDesign::create(['system_key' => 'students']),
        );
        $design->selected_for_workspace_id = $workspaceA->getKey();
        $design->save();

        $this->workspaceB = $workspaceB;
        $this->foreignTeacher = $ownerB;
    });

    it('shows the owner design to a guest', function (): void {
        $this->asGuest();

        $this->getJson("/api/v1/certificates/verify/{$this->certificate->verification_code}")
            ->assertOk()
            ->assertJsonPath('certificate.design.image_url', '/certificate-templates/students.webp');
    });

    it('shows the owner design to a signed-in teacher from another workspace', function (): void {
        $this->asGuest();
        Sanctum::actingAs($this->foreignTeacher);
        $this->setCurrentWorkspace($this->workspaceB, $this->foreignTeacher);

        $this->getJson("/api/v1/certificates/verify/{$this->certificate->verification_code}")
            ->assertOk()
            ->assertJsonPath('certificate.design.image_url', '/certificate-templates/students.webp')
            ->assertJsonPath(
                'certificate.design.boxes.student.x',
                CertificateTemplateRegistry::find('students')['boxes']['student']['x'],
            );
    });
});
