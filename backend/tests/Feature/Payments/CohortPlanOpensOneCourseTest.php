<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| ٠٣٦ · T110 · SC-009 — a group plan opens THAT group's course, and nothing else.
|
| ⛔ THE MOST DANGEROUS LINE IN THE WHOLE SHIPMENT, AND IT HAD NO BEHAVIOURAL
| MEASUREMENT AT ALL. `ActivateSubscription::coveredCourses()` answered «every
| published course of this teacher» for anything that was not a course-scoped
| plan — so the buyer of one group's term was enrolled in the teacher's entire
| catalogue, with a 201 and nothing in any log. A static analyser names the
| reader of a method; it says nothing about whether its answer is right.
|
| ⚠️ TWO PUBLISHED COURSES IS THE FIXTURE, NOT A DETAIL. With one course in the
| workspace «enrolled in the group's course» and «enrolled in everything» are the
| same set, and the case passes against the defect in full.
|
| ⚠️ AND THE SECOND COURSE IS PUBLISHED. Coverage is read through PUBLISHED
| courses, so a draft would be excluded for a reason that has nothing to do with
| the plan's coverage.
|
| ⚠️ THE OFFICER OWNS A DIFFERENT WORKSPACE — the fixture line that exposed all
| five layers of the 024 defect on this exact approval path.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->sold = courseWithRate((int) $this->workspace->getKey());
    $this->sold->forceFill(['status' => 'published', 'title' => 'الفيزياء'])->save();

    $this->untouched = courseWithRate((int) $this->workspace->getKey());
    $this->untouched->forceFill(['status' => 'published', 'title' => 'الكيمياء'])->save();

    $this->cohort = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->sold->getKey(),
            'created_by' => $this->teacher->getKey(),
            'name' => 'مجموعة السبت',
        ]),
    );

    $this->plan = Plan::factory()->forCohort((string) $this->cohort->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();
});

it('enrols the buyer in the group course alone', function (): void {
    $order = app(PurchaseSubscription::class)->handle(
        $this->student,
        (string) $this->plan->uuid,
        'cohort',
        (string) $this->cohort->uuid,
    );

    app(ApproveOrder::class)->handle($order->refresh(), $this->officer);

    $courseIds = Enrollment::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->pluck('course_id')
        ->map(intval(...))
        ->all();

    expect($courseIds)->toBe([(int) $this->sold->getKey()]);
});

it('stamps the group course on the order itself', function (): void {
    /*
    | ⚠️ THE SAME ANSWER, ASKED ONE STEP EARLIER. `orders.course_id` is written
    | at purchase from the identical question, and a cohort uuid matches zero
    | rows in `courses` — so before ٠٣٦ this column was simply NULL for every
    | group plan, and the officer's «الكورس» column was blank on the very orders
    | the feature creates.
    */
    $order = app(PurchaseSubscription::class)->handle(
        $this->student,
        (string) $this->plan->uuid,
        'cohort',
        (string) $this->cohort->uuid,
    );

    expect((int) $order->course_id)->toBe((int) $this->sold->getKey());
});

it('opens every published course for a teacher-wide plan', function (): void {
    /*
    | ⚠️ THE CONTROL, AND WITHOUT IT THE CASE ABOVE IS GREEN AGAINST A BUILD THAT
    | ENROLS IN NOTHING AT ALL. Workspace coverage really does mean the whole
    | catalogue — the requirement is that the NARROW plan stops being read as the
    | wide one, not that coverage got smaller.
    */
    $wide = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);

    $order = app(PurchaseSubscription::class)->handle(
        $this->student,
        (string) $wide->uuid,
        'cohort',
        (string) $this->cohort->uuid,
    );

    app(ApproveOrder::class)->handle($order->refresh(), $this->officer);

    $courseIds = Enrollment::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->pluck('course_id')
        ->map(intval(...))
        ->all();

    sort($courseIds);

    $expected = [(int) $this->sold->getKey(), (int) $this->untouched->getKey()];
    sort($expected);

    expect($courseIds)->toBe($expected);
});
