<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\BuildTeacherStatement;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\SettlementBasis;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\SettlementRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| SC-016 — the statement must not get slower because the teacher taught more.
|
| Measured by comparing TWO SIZES, never against a fixed allowance. A test that
| asserts "at most 20 queries" passes an N+1 the whole time the fixture is small,
| and the fixture in a test is always small. Comparing 100 units against 10,000
| makes the shape of the growth the assertion: constant is constant at any size,
| and one query per row is a hundredfold difference that no allowance hides.
|
| This is the same measurement 005 added after ClassSessionResource asked every
| published session where its recording went — one SELECT per row, on the
| teacher's calendar and on every student's timetable.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
});

/**
 * `$count` units and one ledger entry each, inserted in bulk.
 *
 * Bulk, because ten thousand factory calls would take longer than the assertion
 * is worth — and bypassing the model means `uuid` and `workspace_id` are
 * supplied by hand: neither HasUuid nor BelongsToWorkspace runs on an insert
 * that never becomes a model.
 */
function seedUnitsWithLedger(int $count): void
{
    $teacherId = (int) test()->teacher->getKey();
    $workspaceId = (int) test()->workspace->getKey();
    $studentId = (int) test()->owner->getKey();
    $now = CarbonImmutable::now();
    $stamp = $now->toDateTimeString();

    // Continue where a previous call stopped: the seat unique index is what makes
    // accrual idempotent, and restarting session ids at 1 collides with it — as
    // it should. The test grows the fixture in two batches, so it has to respect
    // the same rule real accrual does.
    $offset = (int) DB::table('teaching_units')->count();

    $units = [];
    $entries = [];

    for ($i = 0; $i < $count; $i++) {
        $units[] = [
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'student_user_id' => $studentId,
            'teacher_profile_id' => $teacherId,
            // A distinct session per unit: the seat unique index is
            // (class_session_id, student_user_id, reversal_of_id).
            'class_session_id' => $offset + $i + 1,
            'session_type' => ClassSessionType::Individual->value,
            'amount_minor' => 5000,
            'currency' => 'QAR',
            'frozen_seats' => 1,
            'basis' => SettlementBasis::FrozenSeat->value,
            'status' => TeachingUnitStatus::Accrued->value,
            'recording_fault' => false,
            'needs_review' => false,
            'delivered_at' => $stamp,
            'accrued_at' => $stamp,
            'reversal_of_id' => 0,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];

        $entries[] = [
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'teacher_profile_id' => $teacherId,
            'type' => LedgerEntryType::Unit->value,
            'amount_minor' => 5000,
            'currency' => 'QAR',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    foreach (array_chunk($units, 250) as $chunk) {
        DB::table('teaching_units')->insert($chunk);
    }

    foreach (array_chunk($entries, 500) as $chunk) {
        DB::table('ledger_entries')->insert($chunk);
    }
}

function statementQueryCount(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(BuildTeacherStatement::class)->handle(test()->teacher);

    $queries = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $queries;
}

it('costs the same number of queries at a hundred units as at ten thousand', function (): void {
    seedUnitsWithLedger(100);

    // One throwaway build first. `PlatformSettings` reads its rows once and
    // caches them, so the very first statement of a process costs two extra
    // queries — a difference that has nothing to do with the row count and would
    // make the comparison below measure the cache instead.
    statementQueryCount();

    $small = statementQueryCount();

    seedUnitsWithLedger(9_900);
    $large = statementQueryCount();

    expect(DB::table('teaching_units')->count())->toBe(10_000)
        // The whole assertion. Not "fewer than N" — the SAME, because anything
        // that grows with the row count grows a hundredfold between these two
        // measurements and would still sit inside a generous fixed allowance.
        ->and($large)->toBe($small)
        // And a sanity floor: a zero here would mean the statement was cached or
        // never built, and the comparison above would hold vacuously.
        ->and($small)->toBeGreaterThan(0);
});

it('aggregates rather than loading the rows it counts', function (): void {
    seedUnitsWithLedger(500);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(BuildTeacherStatement::class)->handle($this->teacher);

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // Every read of the two big tables must be an aggregate. A plain SELECT over
    // ten thousand units is one query and would pass the count test above while
    // pulling the whole window into memory — the failure mode the count cannot
    // see (NFR-011).
    foreach ($log as $entry) {
        $sql = strtolower((string) $entry['query']);

        if (! str_contains($sql, 'from "teaching_units"') && ! str_contains($sql, 'from "ledger_entries"')) {
            continue;
        }

        expect($sql)->toMatch('/count\(|sum\(|min\(|max\(/');
    }
});
