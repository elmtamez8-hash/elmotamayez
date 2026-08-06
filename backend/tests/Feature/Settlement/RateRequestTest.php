<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\DecideRateChange;
use App\Modules\Settlement\Actions\RequestRateChange;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Modules\Settlement\Events\SettlementRateApproved;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Support\RateResolver;
use App\Modules\Tenancy\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/*
| SC-005أ — no rate takes effect without approval, and the approval is not a
| formality.
|
| Approving a settlement rate moves the sale price through the cost-plus formula
| in 006, so it changes what every visitor to the marketplace sees. Letting the
| teacher set it directly would mean the person who moved the number and the
| person blamed for the drop in conversion are different people (Q2).
|
| The structural guarantee is that approval is the ONLY thing in the system that
| writes a settlement_rates row. Not a rule to remember — the only writer.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    $this->current = SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
});

/** What the resolver says the teacher is worth right now. */
function rateNow(): ?int
{
    return app(RateResolver::class)->resolve(
        (int) test()->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now(),
    )?->amount_minor;
}

it('does not move the rate while the request is pending', function (): void {
    app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 9000, $this->owner);

    // Asked for, not granted. Between the two, work is still priced at the old
    // rate — including work done a minute after the request was filed.
    expect(rateNow())->toBe(5000)
        ->and(SettlementRate::query()->count())->toBe(1);
});

it('creates the new rate only on approval, and announces it', function (): void {
    Event::fake([SettlementRateApproved::class]);

    $request = app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 9000, $this->owner);

    app(DecideRateChange::class)->approve($request, $this->owner);

    expect(rateNow())->toBe(9000)
        ->and(SettlementRate::query()->count())->toBe(2);

    // The event 006 will consume to reprice NEW packages. It has no listener yet
    // and that is recorded in contracts/events.md rather than left to be
    // discovered (FR-013ج).
    Event::assertDispatched(SettlementRateApproved::class);
});

it('leaves the old rate standing when the request is rejected', function (): void {
    $request = app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 9000, $this->owner);

    app(DecideRateChange::class)->reject($request, $this->owner, 'السعر أعلى من متوسط المادة.');

    expect(rateNow())->toBe(5000)
        ->and($request->fresh()->status)->toBe(RateRequestStatus::Rejected)
        // FR-013أ — the teacher is told WHY, because "no" with no reason is a
        // request they will simply file again next week.
        ->and($request->fresh()->decision_reason)->toBe('السعر أعلى من متوسط المادة.');
});

it('records both amounts and both people on the decision', function (): void {
    $request = app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 9000, $this->owner);

    app(DecideRateChange::class)->approve($request, $this->owner);

    $decided = $request->fresh();

    // FR-012 — readable a year later without reconstructing what the rate was at
    // the time.
    expect($decided->current_amount_minor)->toBe(5000)
        ->and($decided->requested_amount_minor)->toBe(9000)
        ->and($decided->requested_by)->toBe($this->owner->getKey())
        ->and($decided->decided_by)->toBe($this->owner->getKey())
        ->and($decided->decided_at)->not->toBeNull();
});

it('refuses a second pending request on the same scope', function (): void {
    app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 9000, $this->owner);

    // Two open requests for one rate is a queue where the second silently
    // overwrites whatever the first was approved at.
    expect(fn () => app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 9500, $this->owner))
        ->toThrow(DomainException::class);
});

it('refuses more requests than the window allows', function (): void {
    PlatformSettings::set('settlement.rate_requests_per_window', 1);
    PlatformSettings::set('settlement.rate_request_window_days', 30);

    $first = app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 9000, $this->owner);
    app(DecideRateChange::class)->approve($first, $this->owner);

    // FR-013ب — a price that can be changed at will is not a price. The refusal
    // names when the next request becomes possible, because "no" with no date is
    // a support ticket.
    expect(fn () => app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 11000, $this->owner))
        ->toThrow(DomainException::class);
});

it('does not reprice work already done when a new rate is approved', function (): void {
    $before = app(RateResolver::class)->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now()->subMonth(),
    )?->amount_minor;

    $request = app(RequestRateChange::class)->handle($this->teacher, ClassSessionType::Individual, 9000, $this->owner);
    app(DecideRateChange::class)->approve($request, $this->owner);

    $after = app(RateResolver::class)->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now()->subMonth(),
    )?->amount_minor;

    // SC-005ج — last month is still priced at last month's rate. FR-011 forbids
    // the alternative, and the alternative is one UPDATE away.
    expect($after)->toBe($before)->toBe(5000);
});
