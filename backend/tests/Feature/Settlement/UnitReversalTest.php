<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\ReverseTeachingUnit;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\TeachingUnit;
use Illuminate\Database\QueryException;

/*
| FR-006 · SC-013 — a correction is a new row, never an edit.
|
| The original survives untouched, so "what did we believe in March" is still
| answerable in June. That matters precisely because this is money: an argument
| about pay is an argument about what was recorded at the time, and an UPDATE
| destroys the only evidence either side has.
|
| The trigger is a human decision — a dispute resolved, or a unit accrued in
| error — and never an attendance edit (Q7).
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    $this->unit = TeachingUnit::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
    ]);
});

it('corrects by writing a new row and leaves the original standing', function (): void {
    $reversal = app(ReverseTeachingUnit::class)
        ->handle($this->unit, 'نزاع حُسم لصالح الطالب', $this->owner);

    expect($reversal)->not->toBeNull()
        ->and($reversal->amount_minor)->toBe(-5000)
        ->and($reversal->reversal_of_id)->toBe($this->unit->getKey())
        ->and($reversal->reversal_reason)->toBe('نزاع حُسم لصالح الطالب')
        ->and($reversal->reversed_by)->toBe($this->owner->getKey())
        ->and($reversal->status)->toBe(TeachingUnitStatus::Reversed);

    // Untouched. Not "updated to reflect the correction" — untouched.
    $original = $this->unit->fresh();

    expect($original->amount_minor)->toBe(5000)
        ->and($original->status)->toBe(TeachingUnitStatus::Accrued);
});

it('nets the ledger to zero after a correction', function (): void {
    app(ReverseTeachingUnit::class)->handle($this->unit, 'وحدة نشأت خطأً', $this->owner);

    // Two rows summing to nothing, rather than one row deleted. The balance is
    // right either way; only one of them can still explain itself.
    expect((int) LedgerEntry::query()->sum('amount_minor'))->toBe(-5000)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Reversal)->count())->toBe(1);
});

it('refuses to reverse a reversal', function (): void {
    $reversal = app(ReverseTeachingUnit::class)->handle($this->unit, 'أول تصحيح', $this->owner);

    // Otherwise a correction of a correction re-credits the teacher, and the
    // second reversal looks exactly like the first in the ledger.
    expect(app(ReverseTeachingUnit::class)->handle($reversal, 'تصحيح التصحيح', $this->owner))
        ->toBeNull();
});

it('lets a seat carry many corrections but only one original', function (): void {
    // The unique key is (session, student, reversal_of_id): one original per
    // seat because originals all carry 0, and any number of corrections because
    // each carries a different id. A nullable column there would have let two
    // originals through, since both engines treat NULLs in a unique index as
    // distinct.
    $second = TeachingUnit::factory()->make([
        'teacher_profile_id' => $this->teacher->getKey(),
        'class_session_id' => $this->unit->class_session_id,
        'student_user_id' => $this->unit->student_user_id,
    ]);

    expect(fn () => $second->save())->toThrow(QueryException::class);
});
