<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Support\ReviewEligibility;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Laravel\Sanctum\Sanctum;

/*
| Security scan 2026-10-10 — F18 · F21 · F27.
*/

it('roots the email verification link at app.url whatever Host the request carried', function (): void {
    config(['app.url' => 'https://elmotamayez.tech']);
    app(UrlGenerator::class)->setRequest(Request::create('http://attacker.example/api/v1/auth/register/student', 'POST'));

    $user = User::factory()->unverified()->create();
    $link = (new VerifyEmail)->toMail($user)->actionUrl;

    expect($link)->toStartWith('https://elmotamayez.tech/')
        ->and($link)->not->toContain('attacker.example');
});

it('stores an invitation token as its hash and still accepts the plain one', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $token = (string) $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
        'email' => 'helper@example.com',
        'role' => Roles::ASSISTANT_TEACHER,
    ])->assertCreated()->json('token');

    $stored = (string) Invitation::query()->withoutWorkspaceScope()->value('token');

    expect($stored)->not->toBe($token)
        ->and($stored)->toBe(hash('sha256', $token));

    $this->asGuest();
    $this->getJson("/api/v1/workspaces/invitations/{$token}")->assertOk();
    $this->getJson("/api/v1/workspaces/invitations/{$stored}")->assertNotFound();
});

it('refuses a review from a member of the teacher\'s own staff', function (): void {
    $workspace = marketplaceWorkspace();
    $teacher = marketplaceTeacher($workspace);
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    $verdict = app(ReviewEligibility::class)->for($teacher, $assistant);

    expect($verdict['eligible'])->toBeFalse()
        ->and($verdict['reason'])->toBe('لا يمكن لفريق المدرّس تقييمه.');
});
