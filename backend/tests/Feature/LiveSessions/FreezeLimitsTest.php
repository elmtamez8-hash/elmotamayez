<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Events\FreezePeriodChanged;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| Owner decision 2026-09-27 — two limits on a freeze, enforced in the Action:
|
|   - one period runs at most 30 days, both ends included;
|   - at most 2 periods may START in one calendar month, per scope (the whole
|     workspace, or one student in it).
|
| Both are `platform_settings` rows, and the screen reads them from the index.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    // A whole month in the future, so no period here touches today.
    $this->month = CarbonImmutable::now()->addMonths(2)->startOfMonth();
});

function freezeLimitsCreate(CarbonImmutable $from, CarbonImmutable $to, ?User $student = null): FreezePeriod
{
    return app(CreateFreezePeriod::class)->handle(test()->owner, $from, $to, $student, 'إجازة')['period'];
}

function freezeLimitsStudent(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);

    return $student;
}

it('accepts a period of exactly thirty days and refuses thirty-one', function (): void {
    $start = $this->month->addDays(2);

    expect(fn () => freezeLimitsCreate($start, $start->addDays(30)))
        ->toThrow(DomainException::class, 'لا يجوز أن تتجاوز فترة التجميد الواحدة ٣٠ يوماً.');
    expect(FreezePeriod::query()->count())->toBe(0);

    // Both ends included: day 1 .. day 30.
    $period = freezeLimitsCreate($start, $start->addDays(29));
    expect($period->exists)->toBeTrue()
        ->and($period->starts_on->toDateString())->toBe($start->toDateString())
        ->and($period->ends_on->toDateString())->toBe($start->addDays(29)->toDateString());
});

it('lets two periods start in a month, refuses the third, and counts the next month afresh', function (): void {
    freezeLimitsCreate($this->month->addDays(1), $this->month->addDays(2));
    // A period that runs INTO the next month still started in this one.
    freezeLimitsCreate($this->month->endOfMonth()->startOfDay(), $this->month->addMonth()->addDays(3));

    expect(fn () => freezeLimitsCreate($this->month->addDays(10), $this->month->addDays(11)))
        ->toThrow(DomainException::class, 'لا تبدأ في الشهر الواحد أكثر من فترتي تجميد، وقد بلغ هذا الشهر الحدّ. اختر بداية في شهر آخر.');

    expect(FreezePeriod::query()->count())->toBe(2);

    freezeLimitsCreate($this->month->addMonth()->addDays(10), $this->month->addMonth()->addDays(11));
    expect(FreezePeriod::query()->count())->toBe(3);
});

it('counts each scope on its own: a workspace at its ceiling still lets one student be frozen', function (): void {
    $student = freezeLimitsStudent();

    freezeLimitsCreate($this->month->addDays(1), $this->month->addDays(2));
    freezeLimitsCreate($this->month->addDays(5), $this->month->addDays(6));

    // The student's own scope is empty this month.
    freezeLimitsCreate($this->month->addDays(8), $this->month->addDays(9), $student);
    freezeLimitsCreate($this->month->addDays(12), $this->month->addDays(13), $student);

    expect(fn () => freezeLimitsCreate($this->month->addDays(15), $this->month->addDays(16), $student))
        ->toThrow(DomainException::class, 'لهذا الطالب');
});

it('announces the new period exactly as the model used to, so subscriptions are extended', function (): void {
    Event::fake([FreezePeriodChanged::class]);

    $student = freezeLimitsStudent();
    freezeLimitsCreate($this->month->addDays(1), $this->month->addDays(2), $student);

    Event::assertDispatched(
        FreezePeriodChanged::class,
        fn (FreezePeriodChanged $event): bool => $event->workspaceId === (int) $this->workspace->getKey()
            && $event->studentUserId === (int) $student->getKey(),
    );
});

it('reads both limits from platform settings', function (): void {
    PlatformSettings::set('sessions.freeze_max_days', 3);
    PlatformSettings::set('sessions.freeze_max_per_month', 1);

    expect(fn () => freezeLimitsCreate($this->month->addDays(1), $this->month->addDays(3)))
        ->not->toThrow(DomainException::class);
    expect(fn () => freezeLimitsCreate($this->month->addDays(10), $this->month->addDays(10)))
        ->toThrow(DomainException::class, 'فترة تجميد واحدة');
    expect(fn () => freezeLimitsCreate($this->month->addMonth(), $this->month->addMonth()->addDays(3)))
        ->toThrow(DomainException::class, 'أيام');
});

it('answers 422 in Arabic over the API and shows the limits beside the list', function (): void {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/freeze-periods')
        ->assertOk()
        ->assertJsonPath('limits.max_days', 30)
        ->assertJsonPath('limits.max_per_month', 2);

    $this->postJson('/api/v1/freeze-periods', [
        'starts_on' => $this->month->toDateString(),
        'ends_on' => $this->month->addDays(40)->toDateString(),
    ])->assertStatus(422)
        ->assertJsonPath('message', 'لا يجوز أن تتجاوز فترة التجميد الواحدة ٣٠ يوماً.');
});

it('leaves an existing longer period untouched', function (): void {
    // Declared before the limit existed — nothing rewrites or refuses it.
    $old = FreezePeriod::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'starts_on' => $this->month->subMonths(3)->toDateString(),
        'ends_on' => $this->month->subMonths(3)->addDays(59)->toDateString(),
        'created_by' => $this->owner->getKey(),
    ]);

    freezeLimitsCreate($this->month->addDays(1), $this->month->addDays(2));

    expect($old->fresh()?->ends_on->toDateString())->toBe($this->month->subMonths(3)->addDays(59)->toDateString());
});
