<?php

declare(strict_types=1);

use App\Filament\Resources\EnrollmentResource\Pages\EditEnrollment;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Events\SubscriptionEnded;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| «منتهٍ» from `/admin` on a SUBSCRIPTION enrolment, and the way back.
|
| ⚠️ The panel wrote the column raw: `expired` fired no `SubscriptionEnded`, so
| `ReleaseSeatsOnSubscriptionEnd` never ran and the student kept — and was later
| charged for — every booked seat in a course they could no longer open. And
| `expired → active` handed a month of paid access back with no payment and no
| subscription that would ever close it again.
|
| ⚠️ Two workspaces, the officer owning the other one — the shape that exposes a
| scoped read in the panel (spec 024's five layers).
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$home] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    [$away, $teacher] = $this->createWorkspaceWithOwner(['name' => 'مساحة المدرّس']);

    $this->away = $away;
    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $away,
        fn (): Course => Course::factory()->create([
            'workspace_id' => $away->getKey(),
            'created_by' => $teacher->getKey(),
        ]),
    );

    $officer = User::factory()->create(['is_super_admin' => true]);
    $officer->forceFill(['last_workspace_id' => $home->getKey()])->save();

    $this->actingAs($officer);
});

function subscriptionPanelEnrollment(string $status, string $source = 'subscription'): Enrollment
{
    return Enrollment::query()->withoutWorkspaceScope()->create([
        'workspace_id' => test()->away->getKey(),
        'course_id' => test()->course->getKey(),
        'student_user_id' => User::factory()->create()->getKey(),
        'source' => $source,
        'status' => $status,
        'enrolled_at' => now(),
    ]);
}

function subscriptionPanelStatus(Enrollment $enrollment): string
{
    return (string) Enrollment::query()->withoutWorkspaceScope()->whereKey($enrollment->getKey())->value('status');
}

it('releases the seats when a subscription enrolment is expired from the panel', function (): void {
    Event::fake([SubscriptionEnded::class]);

    $enrollment = subscriptionPanelEnrollment('active');

    Livewire::test(EditEnrollment::class, ['record' => $enrollment->getRouteKey()])
        ->fillForm(['status' => 'expired'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(subscriptionPanelStatus($enrollment))->toBe('expired');

    Event::assertDispatched(SubscriptionEnded::class, fn (SubscriptionEnded $event): bool => $event->studentUserId === (int) $enrollment->student_user_id
        && $event->courseIds === [(int) test()->course->getKey()]
        && $event->workspaceId === (int) test()->away->getKey());
});

it('does not offer to reopen a lapsed subscription enrolment', function (): void {
    $enrollment = subscriptionPanelEnrollment('expired');

    Livewire::test(EditEnrollment::class, ['record' => $enrollment->getRouteKey()])
        ->assertFormFieldExists('status', fn (Select $field): bool => ! array_key_exists('active', $field->getOptions())
            && ! array_key_exists('completed', $field->getOptions()))
        ->fillForm(['status' => 'active'])
        ->call('save')
        ->assertHasFormErrors(['status']);

    expect(subscriptionPanelStatus($enrollment))->toBe('expired');
});

/*
| The option list refuses a forged save first (its own `in` rule), so the case
| above stays green with the page guard deleted. This drives the guard itself,
| for both granting statuses — `completed` opens the course exactly as `active`
| does.
*/
it('refuses active and completed for a lapsed subscription at the save handler itself', function (string $to): void {
    $enrollment = subscriptionPanelEnrollment('expired');

    $page = Livewire::test(EditEnrollment::class, ['record' => $enrollment->getRouteKey()])->instance();
    $guard = new ReflectionMethod($page, 'mutateFormDataBeforeSave');

    expect(fn () => $guard->invoke($page, ['status' => $to]))
        ->toThrow(ValidationException::class, 'بتجديده');
})->with(['active', 'completed']);

it('still reopens an expired purchase by hand', function (): void {
    $enrollment = subscriptionPanelEnrollment('expired', 'purchase');

    Livewire::test(EditEnrollment::class, ['record' => $enrollment->getRouteKey()])
        ->fillForm(['status' => 'active'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(subscriptionPanelStatus($enrollment))->toBe('active');
});
