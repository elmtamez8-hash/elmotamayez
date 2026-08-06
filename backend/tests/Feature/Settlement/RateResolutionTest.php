<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Support\RateResolver;
use Carbon\CarbonImmutable;

/*
| SC-005ج — which rate applies when several could.
|
| The ordering is declared once and constant (FR-014ب): most specific first —
| subject AND grade, then subject, then the teacher's general rate — and within
| one level, the newest that had already taken effect.
|
| The default is deliberately the simple case: one rate per teacher per session
| type. Narrowing by subject and grade is a capability the admin may reach for,
| not a shape every teacher has to fill in (FR-014أ).
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->resolver = app(RateResolver::class);

    // All three levels, all in force, all different amounts.
    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 3000,
        'subject_id' => null,
        'grade_level' => null,
    ]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
        'subject_id' => 7,
        'grade_level' => null,
    ]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 8000,
        'subject_id' => 7,
        'grade_level' => 'grade-12',
    ]);
});

it('prefers subject and grade over subject alone', function (): void {
    $rate = $this->resolver->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now(),
        subjectId: 7,
        gradeLevel: 'grade-12',
    );

    expect($rate?->amount_minor)->toBe(8000);
});

it('prefers subject alone over the general rate', function (): void {
    $rate = $this->resolver->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now(),
        subjectId: 7,
        gradeLevel: 'grade-9',
    );

    // The grade-12 row does not apply to a grade-9 session, so the subject rate
    // wins rather than the most expensive one.
    expect($rate?->amount_minor)->toBe(5000);
});

it('falls back to the general rate for a subject with none of its own', function (): void {
    $rate = $this->resolver->resolve(
        (int) $this->teacher->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now(),
        subjectId: 99,
    );

    expect($rate?->amount_minor)->toBe(3000);
});

it('returns nothing rather than guessing when no rate applies', function (): void {
    $other = TeacherProfile::factory()->create();

    $rate = $this->resolver->resolve(
        (int) $other->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now(),
    );

    // Null, not a default. Inventing a price for a teacher nobody agreed one
    // with is how a settlement becomes a dispute — the unit is written unpriced
    // and flagged instead.
    expect($rate)->toBeNull();
});
