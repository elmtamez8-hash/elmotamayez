<?php

declare(strict_types=1);

use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use Laravel\Sanctum\Sanctum;

/*
| ⚠️ EVERY ONE OF THESE RULES LIVES IN `SaveFieldBoxes`, NOT IN THE FORM REQUEST
| (المبدأ الثاني) — the seeder and the Filament panel reach that Action with no
| form behind them. What they would write is a field drawn where nobody can see
| it, and the first person to find out is a student looking at their own
| certificate.
*/
describe('adjusting where the six fields sit', function (): void {
    beforeEach(function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'Academy']);

        Sanctum::actingAs($owner);
        $this->setCurrentWorkspace($workspace, $owner);

        $this->uuid = $this->postJson('/api/v1/certificate-designs', ['system_key' => 'classic'])
            ->assertCreated()
            ->json('uuid');

        // Valid by construction: the registry's own measured positions.
        $this->boxes = CertificateTemplateRegistry::find('classic')['boxes'];
    });

    it('stores the six boxes it was given', function (): void {
        $boxes = $this->boxes;
        $boxes['student']['x'] = 0.1;

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => $boxes])
            ->assertOk()
            ->assertJsonPath('boxes.student.x', 0.1);
    });

    it('refuses a box outside the image', function (): void {
        $boxes = $this->boxes;
        $boxes['student']['y'] = 1.4;

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => $boxes])
            ->assertStatus(422);
    });

    it('refuses a box that starts inside and ends outside', function (): void {
        // ⚠️ The common case, and the one a bare range check lets through: every
        // value is inside 0–1 and the box still hangs off the edge (`FR-020`).
        $boxes = $this->boxes;
        $boxes['student']['x'] = 0.9;
        $boxes['student']['w'] = 0.4;

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => $boxes])
            ->assertStatus(422);
    });

    it('refuses a key the design has no field for', function (): void {
        $boxes = $this->boxes;
        $boxes['signature'] = $boxes['student'];

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => $boxes])
            ->assertStatus(422);
    });

    it('refuses five keys, because the sixth would be drawn from nowhere', function (): void {
        $boxes = $this->boxes;
        unset($boxes['qr']);

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => $boxes])
            ->assertStatus(422);
    });

    it('refuses a minimum font above its maximum', function (): void {
        $boxes = $this->boxes;
        $boxes['teacher']['min_font'] = 0.09;
        $boxes['teacher']['max_font'] = 0.02;

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => $boxes])
            ->assertStatus(422);
    });

    it('refuses a colour no theme token answers', function (): void {
        // ⚠️ An undefined token paints NOTHING, silently — four times in this tree.
        $boxes = $this->boxes;
        $boxes['student']['color'] = 'brand-gold';

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => $boxes])
            ->assertStatus(422);
    });

    it('gives all six back to the template positions on reset', function (): void {
        $boxes = $this->boxes;
        $boxes['student']['x'] = 0.01;

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => $boxes])->assertOk();

        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => null])
            ->assertOk()
            ->assertJsonPath('boxes.student.x', $this->boxes['student']['x'])
            ->assertJsonCount(6, 'boxes');
    });

    it('refuses a student at the door', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'Other']);
        $student = $this->addWorkspaceMember($workspace, 'student');

        Sanctum::actingAs($student);
        $this->setCurrentWorkspace($workspace, $student);

        // Route-model binding runs the workspace scope first, so a foreign row is a
        // 404 before any policy is asked — which is the correct answer for a design
        // that is not theirs. The permission check is what `DesignSelectionTest`
        // measures from the allow side.
        $this->patchJson("/api/v1/certificate-designs/{$this->uuid}", ['boxes' => null])
            ->assertNotFound();
    });
});
