<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Support\RateResolver;
use Carbon\CarbonImmutable;

/*
| SC-005 · SC-005أ — the past keeps its price.
|
| Without versioning, one edit reprices every hour a teacher has ever taught, and
| the argument that follows is exactly the one this whole context was built to
| prevent. So a rate row is written and never updated, and the resolver asks
| "what was in force WHEN THE SESSION RAN" rather than "what is in force now".
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->resolver = app(RateResolver::class);
});

it('prices a session by the rate in force when it ran, not by the newest', function (): void {
    $january = CarbonImmutable::parse('2026-01-01');
    $june = CarbonImmutable::parse('2026-06-01');

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 4000,
        'effective_from' => $january,
    ]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 7000,
        'effective_from' => $june,
    ]);

    $march = $this->resolver->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::parse('2026-03-15'),
    );

    $july = $this->resolver->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::parse('2026-07-15'),
    );

    // A March session stays at the March price forever, whatever happens in June.
    expect($march?->amount_minor)->toBe(4000)
        ->and($july?->amount_minor)->toBe(7000);
});

it('ignores a rate that had not taken effect yet', function (): void {
    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 4000,
        'effective_from' => CarbonImmutable::parse('2026-01-01'),
    ]);

    // Approved now, effective next month. Between the two dates it does not
    // exist as far as pricing is concerned.
    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 9000,
        'effective_from' => CarbonImmutable::parse('2026-09-01'),
    ]);

    $rate = $this->resolver->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::parse('2026-08-15'),
    );

    expect($rate?->amount_minor)->toBe(4000);
});

it('picks the newest rate at the same specificity, whatever the timestamps look like', function (): void {
    // Timestamps chosen so a string comparison gets it wrong: the second is
    // LATER, but its digits sort lower. Sorting a formatted "specificity-epoch"
    // string put "3-999…" above "3-1000…" because '9' beats '1', and the older
    // rate won roughly one time in ten.
    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 4000,
        'effective_from' => CarbonImmutable::createFromTimestamp(999_999_999),
    ]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 6000,
        'effective_from' => CarbonImmutable::createFromTimestamp(1_000_000_000),
    ]);

    $rate = $this->resolver->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::createFromTimestamp(1_100_000_000),
    );

    expect($rate?->amount_minor)->toBe(6000);
});

it('keeps the individual and group rates apart', function (): void {
    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
    ]);

    SettlementRate::factory()->group()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 1800,
    ]);

    // Hosting a group session costs the platform once, not once per student, so
    // a single rate across both would quietly double the margin on groups
    // (FR-014 · Q2أ).
    $individual = $this->resolver->resolve((int) $this->teacher->getKey(), ClassSessionType::Individual, CarbonImmutable::now());
    $group = $this->resolver->resolve((int) $this->teacher->getKey(), ClassSessionType::Group, CarbonImmutable::now());

    expect($individual?->amount_minor)->toBe(5000)
        ->and($group?->amount_minor)->toBe(1800);
});

it('never carries a percentage anywhere in the pricing', function (): void {
    $columns = array_keys(SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
    ])->getAttributes());

    // SC-006 — a percentage of the sale price lets the teacher recover the total
    // with one division, which dismantles the separation without touching a
    // single screen. The absence is the guarantee (FR-009 · Q2أ).
    foreach ($columns as $column) {
        expect($column)->not->toContain('percent')
            ->and($column)->not->toContain('rate_percentage')
            ->and($column)->not->toContain('share');
    }
});
