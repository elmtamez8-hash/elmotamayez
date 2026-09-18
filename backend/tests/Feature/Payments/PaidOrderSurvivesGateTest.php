<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| ٠٣٦ · T038 · FR-018 — an order that was PAID FOR is approved whatever the price
| does afterwards.
|
| ⛔ A MANUAL TRANSFER TAKES DAYS, AND THE PLAN CAN LEGITIMATELY MOVE INSIDE THAT
| LAG. The student chose a group while it was on sale and paid for it; a teacher
| switching the plan off in the meantime must not turn that into a refusal at the
| officer's desk, leaving somebody holding a payment and no group. The decision
| about price was taken at purchase. What is still genuinely open at approval is
| whether the room is open and has a chair — which is why `ApproveOrder` asks
| `isStructurallyJoinable()` and the «structurally» in that name is the exemption
| written where it is read.
|
| ⚠️ THE ORDER KIND IS A CONDITION OF THE FIXTURE, NOT A DETAIL. A COURSE order
| leaves through the enrolment arm and never reaches the cohort branch at all —
| so a case built on one passes with the defect fully present.
|
| ⚠️ AND THE MEMBERSHIP ROW IS THE ASSERTION. «The approval succeeded» is equally
| true of a handler that answered and wrote nothing.
|
| ⚠️ THE OFFICER OWNS A DIFFERENT WORKSPACE — the one fixture line that exposed
| all five layers of the 024 defect on this exact approval path.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->plan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $this->cohort = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'created_by' => $this->teacher->getKey(),
            'name' => 'مجموعة السبت',
        ]),
    );

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();
});

it('approves a paid subscription order after its plan is switched off', function (): void {
    // Bought while the plan was live — this is the only moment the price was
    // ever a question.
    $order = app(PurchaseSubscription::class)->handle(
        $this->student,
        (string) $this->plan->uuid,
        'cohort',
        (string) $this->cohort->uuid,
    );

    // Days pass, and the teacher switches the plan off. From now on no student
    // may join this group of their own accord.
    $this->plan->forceFill(['is_active' => false])->save();

    app(ApproveOrder::class)->handle($order->refresh(), $this->officer);

    $membership = CohortMembership::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->whereNull('closed_at')
        ->first();

    expect($membership)->not->toBeNull()
        ->and((int) $membership->cohort_id)->toBe((int) $this->cohort->getKey());
});

it('refuses the same group to a student walking up to it', function (): void {
    /*
    | ⚠️ THE CONTROL THAT MAKES THE EXEMPTION MEAN SOMETHING. With the plan off,
    | the student's own door is shut on the identical group — so the case above
    | is about WHO is asking and when, rather than about a gate that was never
    | built.
    */
    $this->plan->forceFill(['is_active' => false])->save();

    // Enrolled already — otherwise the door refuses one step earlier, with 403
    // «لست مسجَّلاً», and the case measures the wrong guard entirely.
    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/cohorts/'.$this->cohort->uuid.'/join')
        ->assertStatus(422)
        ->assertJsonPath('code', 'cohort_not_listed');
});
