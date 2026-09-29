<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\UnlockExemption;
use App\Modules\Assessments\Models\UnlockRule;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on the unlock condition (spec 008 · US7): the rules and the
| per-session exemptions were guarded by `unlock.rules.manage` alone, so an
| assistant confined to one course who held it let students past — and rewrote
| the rule — on every course and session in the workspace.
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST: «far is refused» alone is green against an
| assistant refused everything.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->nearSession = billableSession($this->workspace, $this->owner, $this->near);
    $this->farSession = billableSession($this->workspace, $this->owner, $this->far);
    $this->courseless = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => TeacherProfile::query()->where('user_id', $this->owner->getKey())->value('id'),
        'course_id' => null,
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->near, $this->student);
    $this->createEnrollment($this->workspace, $this->far, $this->student);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    $this->assistant->givePermissionTo(Permissions::UNLOCK_RULES_MANAGE);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->assistant->unsetRelation('permissions');

    // A default and one override per course, written by the teacher.
    foreach ([UnlockRule::DEFAULT_SCOPE, $this->near->getKey(), $this->far->getKey()] as $courseId) {
        UnlockRule::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $courseId,
            'requires_attendance' => true,
            'requires_assignment' => false,
            'min_score_pct' => 50,
            'is_active' => true,
        ]);
    }
});

function confineUnlockAssistantTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    app()->forgetScopedInstances();
}

/** @return array{list: int, exempt: int} */
function unlockExemptionStatuses(ClassSession $session, string $studentUuid): array
{
    return [
        'list' => test()->getJson("/api/v1/manage/class-sessions/{$session->uuid}/unlock-exemptions")->status(),
        'exempt' => test()->postJson("/api/v1/manage/class-sessions/{$session->uuid}/unlock-exemptions", [
            'student_uuid' => $studentUuid,
            'reason' => 'ظرفٌ عائلي.',
        ])->status(),
    ];
}

/** @param array<string, mixed> $extra */
function unlockRulePayload(?string $courseUuid, array $extra = []): array
{
    return [
        'course_uuid' => $courseUuid,
        'requires_attendance' => false,
        'requires_assignment' => true,
        'min_score_pct' => 80,
        ...$extra,
    ];
}

it('lets a confined assistant exempt on their own course\'s session and refuses the far and the course-less one', function (): void {
    confineUnlockAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    expect(unlockExemptionStatuses($this->nearSession, $this->student->uuid))->toBe(['list' => 200, 'exempt' => 201]);
    expect(unlockExemptionStatuses($this->farSession, $this->student->uuid))->toBe(['list' => 403, 'exempt' => 403]);
    expect(unlockExemptionStatuses($this->courseless, $this->student->uuid))->toBe(['list' => 403, 'exempt' => 403]);

    // Nothing was written through a refused door.
    expect(UnlockExemption::query()->pluck('class_session_id')->all())->toBe([$this->nearSession->getKey()]);
});

it('leaves an unconfined assistant and the owner every session\'s exemptions', function (): void {
    foreach (['assistant' => $this->assistant, 'owner' => $this->owner] as $who => $user) {
        Sanctum::actingAs($user);

        foreach (['near' => $this->nearSession, 'far' => $this->farSession, 'courseless' => $this->courseless] as $label => $session) {
            expect(unlockExemptionStatuses($session, $this->student->uuid))
                ->toBe(['list' => 200, 'exempt' => 201], "{$who} {$label}");
        }
    }
});

it('lists the default and the confined assistant\'s own overrides only', function (): void {
    $titles = fn (): array => collect($this->getJson('/api/v1/manage/unlock-rules')->assertOk()->json('data'))
        ->map(fn (array $row): string => $row['is_default'] ? 'default' : (string) $row['course_uuid'])
        ->sort()->values()->all();

    Sanctum::actingAs($this->owner);
    expect($titles())->toBe(collect(['default', $this->near->uuid, $this->far->uuid])->sort()->values()->all());

    // Unconfined: the same list.
    Sanctum::actingAs($this->assistant);
    expect($titles())->toBe(collect(['default', $this->near->uuid, $this->far->uuid])->sort()->values()->all());

    confineUnlockAssistantTo($this->near);
    expect($titles())->toBe(collect(['default', $this->near->uuid])->sort()->values()->all());
});

it('lets a confined assistant set their own course\'s rule and refuses the far course\'s and the default', function (): void {
    confineUnlockAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    $this->postJson('/api/v1/manage/unlock-rules', unlockRulePayload($this->near->uuid))->assertCreated();
    $this->postJson('/api/v1/manage/unlock-rules', unlockRulePayload($this->far->uuid))->assertForbidden();
    $this->postJson('/api/v1/manage/unlock-rules', unlockRulePayload(null))->assertForbidden();

    $score = fn (int $courseId): float => (float) UnlockRule::query()->where('course_id', $courseId)->value('min_score_pct');

    expect($score($this->near->getKey()))->toBe(80.0)
        ->and($score($this->far->getKey()))->toBe(50.0)
        ->and($score(UnlockRule::DEFAULT_SCOPE))->toBe(50.0);

    // The owner still writes the default.
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/v1/manage/unlock-rules', unlockRulePayload(null))->assertCreated();
    expect($score(UnlockRule::DEFAULT_SCOPE))->toBe(80.0);
});

it('answers 404 for another workspace\'s session before any scope is asked', function (): void {
    [$other] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);

    $foreign = app(WorkspaceContext::class)->forWorkspace(
        $other,
        fn (): ClassSession => ClassSession::factory()->create(['workspace_id' => $other->getKey()]),
    );

    confineUnlockAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    expect(unlockExemptionStatuses($foreign, $this->student->uuid))->toBe(['list' => 404, 'exempt' => 404]);
});
