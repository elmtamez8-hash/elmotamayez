<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\TeacherFieldAllowlist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| The guard test for the boundary this whole spec exists to draw.
|
| Settlement and billing are two contexts with no foreign key and no shared
| query, and a separation nobody can measure is a separation that erodes one
| convenient field at a time. This file measures it: every key in every payload
| that reaches a teacher, at every nesting depth, against a closed list.
|
| It runs on the statement AND on the export (SC-007 · FR-018 · FR-021). The
| export is the surface everyone forgets — generated once, opened in Excel, never
| reviewed — which is exactly why it shares the allowlist rather than owning one.
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
 * Every string key in a nested payload, flattened.
 *
 * Recursive on purpose: a field smuggled three levels down is still on the wire,
 * and a check on the top level only would pass while the leak sat inside
 * `period` or `deductions`.
 *
 * @return list<string>
 */
function settlementPayloadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...settlementPayloadKeys($child)];
    }

    return $keys;
}

/**
 * Everything in the export that NAMES a field, as opposed to holding a value.
 *
 * The file has two shapes: a key/value summary, then a table. So the names are
 * the first column down to the table's header row, plus that header row itself.
 * Scanning the raw text instead would be a substring match, and "individual"
 * contains "id" — the check would fail on a payload that is entirely correct.
 *
 * @return list<string>
 */
function exportFieldNames(string $csv): array
{
    $header = TeacherFieldAllowlist::EXPORT_COLUMNS;
    $names = [];

    foreach (explode("\n", trim($csv)) as $line) {
        $row = str_getcsv(trim($line, "\r"), escape: '\\');
        $cells = array_map(fn (?string $cell): string => trim((string) $cell, "\u{FEFF}"), $row);

        if ($cells === $header) {
            // The table's header: every cell here is a column name, and every row
            // after it is data.
            return array_values(array_unique([...$names, ...$header]));
        }

        if (($cells[0] ?? '') !== '') {
            $names[] = $cells[0];
        }
    }

    return array_values(array_unique($names));
}

/** A teacher with work of every shape the statement has to describe. */
function seedStatementFixture(): void
{
    $teacherId = (int) test()->teacher->getKey();

    TeachingUnit::factory()->count(3)->create([
        'teacher_profile_id' => $teacherId,
        'session_type' => ClassSessionType::Individual,
    ]);

    TeachingUnit::factory()->create([
        'teacher_profile_id' => $teacherId,
        'session_type' => ClassSessionType::Group,
        'frozen_seats' => 8,
    ]);

    TeachingUnit::factory()->pending()->create(['teacher_profile_id' => $teacherId]);
    TeachingUnit::factory()->disputed()->create(['teacher_profile_id' => $teacherId]);

    LedgerEntry::factory()->count(4)->create(['teacher_profile_id' => $teacherId]);
    LedgerEntry::factory()->deduction(1200)->create(['teacher_profile_id' => $teacherId]);

    RateChangeRequest::factory()->create([
        'teacher_profile_id' => $teacherId,
        'requested_by' => test()->owner->getKey(),
    ]);
}

it('sends nothing outside the allowlist on the statement', function (): void {
    seedStatementFixture();

    Sanctum::actingAs($this->owner);

    $payload = $this->getJson('/api/v1/settlement/statement')->assertOk()->json();

    $unexpected = array_values(array_diff(
        array_unique(settlementPayloadKeys($payload)),
        TeacherFieldAllowlist::STATEMENT,
    ));

    // Named in the failure rather than counted: "expected 0, got 2" sends the
    // next reader back to the payload to find out which two.
    expect($unexpected)->toBe([]);
});

it('sends nothing outside the allowlist on the units list', function (): void {
    seedStatementFixture();

    Sanctum::actingAs($this->owner);

    $rows = $this->getJson('/api/v1/settlement/units')->assertOk()->json('data');

    $unexpected = array_values(array_diff(
        array_unique(settlementPayloadKeys($rows)),
        TeacherFieldAllowlist::UNIT,
    ));

    expect($unexpected)->toBe([]);
});

it('exports the same fields and no others', function (): void {
    seedStatementFixture();

    Sanctum::actingAs($this->owner);

    $csv = $this->get('/api/v1/settlement/statement/export')->assertOk()->streamedContent();

    $allowed = [
        ...TeacherFieldAllowlist::STATEMENT,
        ...TeacherFieldAllowlist::UNIT,
        // The summary block's own two headers, and the ledger types that name a
        // deduction line.
        'key',
        'value',
        ...array_map(fn (LedgerEntryType $type): string => $type->value, LedgerEntryType::cases()),
    ];

    expect(array_values(array_diff(exportFieldNames($csv), $allowed)))->toBe([]);
});

it('never names a field from the billing context, in either surface', function (): void {
    seedStatementFixture();

    Sanctum::actingAs($this->owner);

    $statement = $this->getJson('/api/v1/settlement/statement')->assertOk()->getContent();
    $units = $this->getJson('/api/v1/settlement/units')->assertOk()->getContent();
    $export = $this->get('/api/v1/settlement/statement/export')->assertOk()->streamedContent();

    $exportNames = exportFieldNames($export);

    // The mirror of the allowlist. The list above says what may appear; this one
    // says what may not, under any of the names it goes by — so re-adding one
    // fails a test rather than passing review (FR-018).
    //
    // Quoted in the JSON so the match is on a KEY, and compared cell by cell in
    // the CSV for the same reason: an unquoted substring search reports "id"
    // inside "individual" and fails a payload that is entirely correct.
    foreach (TeacherFieldAllowlist::FORBIDDEN as $forbidden) {
        expect((string) $statement)->not->toContain('"'.$forbidden.'"')
            ->and((string) $units)->not->toContain('"'.$forbidden.'"')
            ->and($exportNames)->not->toContain($forbidden);
    }
});

/*
| SC-012 · FR-022 — the statement's totals ARE the ledger's, at scale.
|
| Not vacuously: the assertion compares against a raw SUM taken with the query
| builder, not against the Action's own reading. And the fixture mixes types on
| purpose — units, reversals, a bonus and a deduction — because a Bonus has no
| teaching_unit behind it, so a total derived from units instead of the ledger
| would omit it and this is the test that would notice.
*/
it('matches the ledger to zero after ten thousand entries', function (): void {
    $teacherId = (int) $this->teacher->getKey();
    $workspaceId = (int) $this->workspace->getKey();

    $now = CarbonImmutable::now()->toDateTimeString();
    $rows = [];

    for ($i = 0; $i < 9_996; $i++) {
        $rows[] = [
            // Supplied by hand: a bulk insert bypasses model events, so neither
            // HasUuid nor BelongsToWorkspace runs. Leaving them out is a NOT NULL
            // violation on the first chunk, which at least fails loudly.
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'teacher_profile_id' => $teacherId,
            // A tenth of them are corrections, so the sum is not just a multiple.
            'type' => $i % 10 === 0 ? LedgerEntryType::Reversal->value : LedgerEntryType::Unit->value,
            'amount_minor' => $i % 10 === 0 ? -5000 : 5000,
            'currency' => 'QAR',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    // Chunked: ten thousand rows times eight columns is well past SQLite's
    // bind-variable ceiling in one statement.
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('ledger_entries')->insert($chunk);
    }

    // The four that make the mix a mix. A Bonus and a Deduction have no unit
    // behind them at all.
    LedgerEntry::factory()->count(2)->create(['teacher_profile_id' => $teacherId]);
    LedgerEntry::factory()->deduction(3300)->create(['teacher_profile_id' => $teacherId]);
    LedgerEntry::factory()->create([
        'teacher_profile_id' => $teacherId,
        'type' => LedgerEntryType::Bonus,
        'amount_minor' => 7700,
    ]);

    Sanctum::actingAs($this->owner);

    $statement = $this->getJson('/api/v1/settlement/statement')->assertOk();

    // Taken independently of the Action, with the query builder, so the two
    // numbers cannot agree merely by sharing a bug.
    $ledgerBalance = (int) DB::table('ledger_entries')
        ->where('teacher_profile_id', $teacherId)
        ->sum('amount_minor');

    expect(LedgerEntry::query()->count())->toBe(10_000)
        ->and($statement->json('net_minor'))->toBe($ledgerBalance);

    // And the halves add up to the whole: a gross that quietly dropped the bonus
    // would still let net match if the deduction dropped with it.
    $deducted = array_sum(array_column((array) $statement->json('deductions'), 'amount_minor'));

    expect($statement->json('gross_minor') + $deducted)->toBe($ledgerBalance);
});

it('counts units and students without counting a correction as work', function (): void {
    $teacherId = (int) $this->teacher->getKey();
    $student = User::factory()->create();

    $unit = TeachingUnit::factory()->create([
        'teacher_profile_id' => $teacherId,
        'student_user_id' => $student->getKey(),
    ]);

    // The correction names the same student and the same session. Counting it
    // would report two sessions taught and — worse — leave the student count
    // right by accident, so only the unit count would ever catch it.
    TeachingUnit::factory()->create([
        'teacher_profile_id' => $teacherId,
        'student_user_id' => $student->getKey(),
        'status' => TeachingUnitStatus::Reversed,
        'amount_minor' => -5000,
        'reversal_of_id' => $unit->getKey(),
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/settlement/statement')
        ->assertOk()
        ->assertJsonPath('students_count', 1)
        ->assertJsonPath('units.by_type.individual', 1)
        ->assertJsonPath('units.reversed', 1);
});
