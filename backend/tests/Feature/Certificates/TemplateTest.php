<?php

declare(strict_types=1);

use App\Modules\Certificates\Models\CertificateTemplate;
use Laravel\Sanctum\Sanctum;

describe('certificate templates', function (): void {
    it('lists templates', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        CertificateTemplate::create([
            'workspace_id' => $workspace->id, 'name' => 'Default', 'html_template' => '<div>{{name}}</div>',
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/certificate-templates')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Default');
    });

    it('creates a template', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/certificate-templates', [
            'name' => 'Gold Template',
            'html_template' => '<h1>{{student_name}}</h1>',
            'defaults' => ['color' => 'gold'],
        ])->assertCreated()->assertJsonPath('name', 'Gold Template');

        expect(CertificateTemplate::where('name', 'Gold Template')->count())->toBe(1);
    });

    it('updates and deletes a template', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $template = CertificateTemplate::create([
            'workspace_id' => $workspace->id, 'name' => 'Old', 'html_template' => '<div>x</div>',
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/certificate-templates/{$template->id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('name', 'Renamed');

        $this->deleteJson("/api/v1/certificate-templates/{$template->id}")->assertNoContent();
        expect(CertificateTemplate::where('id', $template->id)->exists())->toBeFalse();
    });

    it('denies template creation to students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/certificate-templates', ['name' => 'X', 'html_template' => 'x'])
            ->assertForbidden();
    });
});
