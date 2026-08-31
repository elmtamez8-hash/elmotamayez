<?php

declare(strict_types=1);

use App\Modules\Assessments\Enums\Difficulty;

/*
| Spec 012 · T010. The ladder the adaptive path walks (FR-002).
|
| Its own file because the ordering is the one thing every other adaptive test
| assumes and none of them measures: a `harder()` that returned `self` at the top
| would leave every sequence assertion passing while a student sat at `hard` for
| ever, and a `rank()` out of order would make the ceiling comparison read a
| medium concept as already mastered.
*/

it('steps down and stops at the bottom', function (): void {
    expect(Difficulty::Hard->easier())->toBe(Difficulty::Medium)
        ->and(Difficulty::Medium->easier())->toBe(Difficulty::Easy)
        // ⚠️ NULL, NOT `Easy`. A self-returning bottom makes «could not go
        // lower» indistinguishable from «went lower» at every call site.
        ->and(Difficulty::Easy->easier())->toBeNull();
});

it('steps up and stops at the top', function (): void {
    expect(Difficulty::Easy->harder())->toBe(Difficulty::Medium)
        ->and(Difficulty::Medium->harder())->toBe(Difficulty::Hard)
        ->and(Difficulty::Hard->harder())->toBeNull();
});

it('ranks the three in ascending order', function (): void {
    expect(Difficulty::Easy->rank())->toBeLessThan(Difficulty::Medium->rank())
        ->and(Difficulty::Medium->rank())->toBeLessThan(Difficulty::Hard->rank());
});

it('agrees with itself: one step up then down returns to where it started', function (): void {
    foreach (Difficulty::cases() as $case) {
        $up = $case->harder();

        if ($up !== null) {
            expect($up->easier())->toBe($case);
        }
    }
});
