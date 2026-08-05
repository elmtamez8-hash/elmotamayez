<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

/**
 * The overlay that makes a photographed screen traceable.
 *
 * Everything here is about the payload, not the pixels: what the browser is
 * given decides both whether the watermark can identify anyone and whether it
 * leaks the thing it is supposed to protect. Whether it is actually drawn is an
 * e2e question (frontend/e2e/player.spec.ts).
 */

// SC-004. Two viewers, two identities — a shared overlay would name the wrong
// person and make the deterrent worse than nothing.
it('gives each viewer their own watermark', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    $tala = User::factory()->create([
        'first_name' => 'تالا',
        'last_name' => 'القحطاني',
        'phone' => '+97455512345',
        'platform_role' => PlatformRole::Student,
    ]);

    $noor = User::factory()->create([
        'first_name' => 'نور',
        'last_name' => 'المري',
        'phone' => '+97466698765',
        'platform_role' => PlatformRole::Student,
    ]);

    $this->enrolledViewer($workspace, $lesson, $tala);
    $first = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk()->json('watermark');

    $this->enrolledViewer($workspace, $lesson, $noor);
    $second = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk()->json('watermark');

    expect($first)->toBe(['name' => 'تالا القحطاني', 'phone_masked' => '…2345'])
        ->and($second)->toBe(['name' => 'نور المري', 'phone_masked' => '…8765']);
});

// FR-019. Masking in the browser would mean the full number travelled there, and
// a protection feature that ships what it protects is a leak with extra steps.
// So the assertion is against the whole payload, not against the field.
it('never sends the full phone number anywhere in the payload', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    $student = User::factory()->create([
        'phone' => '+97455512345',
        'platform_role' => PlatformRole::Student,
    ]);

    $this->enrolledViewer($workspace, $lesson, $student);

    $payload = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk()->json();
    $serialised = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

    expect($serialised)->not->toContain('97455512345')
        ->and($serialised)->not->toContain('5551234');
});

// A student who signed up without a phone still has to be watermarked; a payload
// that changed shape here would be a crash in the overlay for exactly the
// accounts hardest to trace afterwards.
it('watermarks a viewer who has no phone at all', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    $student = User::factory()->create([
        'first_name' => 'سارة',
        'last_name' => 'العطية',
        'phone' => null,
        'platform_role' => PlatformRole::Student,
    ]);

    $this->enrolledViewer($workspace, $lesson, $student);

    expect($this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk()->json('watermark'))
        ->toBe(['name' => 'سارة العطية', 'phone_masked' => null]);
});

// FR-018 read backwards: the client cannot mint itself more time. Renewal is a
// request the server answers, so a grant whose session is gone renews into a
// refusal — which is what stops playback when the overlay is removed and the
// loop it owns stops running.
it('renews only while the grant is alive', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);
    $this->enrolledViewer($workspace, $lesson);

    $grant = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->json('grant');

    // Longer than the grant's life: exactly what happens when nothing renews it.
    $this->travel(10)->minutes();

    $this->postJson("/api/v1/playback/{$grant}/renew", ['position_seconds' => 12])
        ->assertForbidden();
});
