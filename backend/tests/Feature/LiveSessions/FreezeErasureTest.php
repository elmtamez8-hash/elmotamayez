<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Support\LiveSessionsPersonalData;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| ⛔ `freeze_periods.student_user_id` AND `freeze_period_starts.student_user_id`
| NAME ONE STUDENT, AND UNTIL THIS FILE NEITHER WAS EXPORTED, SWEPT OR ERASED.
| `PersonalDataContractCoverageTest` is a per-MODULE guard and `livesessions` was
| already registered, so both tables sat inside it with the suite green.
|
| ⚠️ THE ROWS THAT MUST SURVIVE ARE ASSERTED AS HARD AS THE ROWS THAT GO. On both
| tables a null student is THE WHOLE WORKSPACE, so an erasure that «anonymised»
| by nulling the column would pass an absence check while turning one student's
| holiday into everybody's — and the workspace's own monthly count is the number
| that would move.
*/

beforeEach(function (): void {
    Storage::fake('local');

    [$workspace] = $this->createWorkspaceWithOwner();
    $this->workspaceId = (int) $workspace->getKey();

    $this->subject = User::factory()->create();
    $this->classmate = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($this->workspaceId, function (): void {
        $this->subjectFreeze = FreezePeriod::factory()->forStudent($this->subject)->create(['workspace_id' => $this->workspaceId]);
        $this->classmateFreeze = FreezePeriod::factory()->forStudent($this->classmate)->create(['workspace_id' => $this->workspaceId]);
        $this->workspaceFreeze = FreezePeriod::factory()->create(['workspace_id' => $this->workspaceId]);
    });

    $today = now()->toDateString();

    DB::table('freeze_period_starts')->insert([
        // The subject's live freeze, and one they had LIFTED — a lift deletes the
        // period, so the ledger line is the only trace of it.
        ['workspace_id' => $this->workspaceId, 'student_user_id' => $this->subject->getKey(), 'starts_on' => $today, 'created_at' => now()],
        ['workspace_id' => $this->workspaceId, 'student_user_id' => $this->subject->getKey(), 'starts_on' => $today, 'created_at' => now()],
        ['workspace_id' => $this->workspaceId, 'student_user_id' => $this->classmate->getKey(), 'starts_on' => $today, 'created_at' => now()],
        ['workspace_id' => $this->workspaceId, 'student_user_id' => null, 'starts_on' => $today, 'created_at' => now()],
    ]);
});

/** The count `CreateFreezePeriod` reads for the whole-workspace scope. */
function freezeErasureWorkspaceStarts(int $workspaceId): int
{
    return DB::table('freeze_period_starts')->where('workspace_id', $workspaceId)->whereNull('student_user_id')->count();
}

function freezeErasureRun(User $subject): DataRequestStatus
{
    $request = app(CreateDataRequest::class)->handle($subject, (string) $subject->uuid, DataRequestType::Erasure);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    return $request->refresh()->status;
}

it('declares the freeze tables in the catalogue, in the mode LiveSessions already erases by', function (): void {
    $row = DataCategory::query()->where('key', 'freeze_period')->sole();

    expect($row->owning_module)->toBe('livesessions')
        ->and($row->table_name)->toBe('freeze_periods')
        ->and($row->column_name)->toBe('student_user_id')
        // ⛔ Any other mode makes `modeFor()` fall back to Retain for the WHOLE module.
        ->and($row->erasure_mode)->toBe(ErasureMode::Anonymise)
        ->and(app(LiveSessionsPersonalData::class)->describe())->toContain('freeze_period');
});

it('deletes the erased student\'s freezes and ledger lines, and never nulls them into the workspace scope', function (): void {
    $workspaceStartsBefore = freezeErasureWorkspaceStarts($this->workspaceId);

    expect(freezeErasureRun($this->subject))->toBe(DataRequestStatus::Completed);

    expect(DB::table('freeze_periods')->where('student_user_id', $this->subject->getKey())->count())->toBe(0)
        ->and(DB::table('freeze_period_starts')->where('student_user_id', $this->subject->getKey())->count())->toBe(0)
        // The workspace scope is exactly as it was: one period, one ledger line.
        ->and(DB::table('freeze_periods')->where('workspace_id', $this->workspaceId)->whereNull('student_user_id')->pluck('id')->all())
        ->toBe([$this->workspaceFreeze->getKey()])
        ->and(freezeErasureWorkspaceStarts($this->workspaceId))->toBe($workspaceStartsBefore)
        // And nobody else's freeze moved.
        ->and(DB::table('freeze_periods')->where('student_user_id', $this->classmate->getKey())->count())->toBe(1)
        ->and(DB::table('freeze_period_starts')->where('student_user_id', $this->classmate->getKey())->count())->toBe(1);

    // A second pass is a no-op rather than a second erasure — which is what
    // proves the predicate shrinks under its own walk.
    expect(freezeErasureRun($this->subject))->toBe(DataRequestStatus::Completed)
        ->and(freezeErasureWorkspaceStarts($this->workspaceId))->toBe($workspaceStartsBefore);
});

it('exports the student\'s own freezes, a lifted one included, and never the workspace\'s', function (): void {
    $rows = [];

    foreach (app(LiveSessionsPersonalData::class)->export(new DataSubject(user: $this->subject)) as $category => $page) {
        if ($category === 'freeze_period') {
            array_push($rows, ...$page);
        }
    }

    $periods = array_values(array_filter($rows, fn (array $row): bool => isset($row['uuid'])));
    $ledger = array_values(array_filter($rows, fn (array $row): bool => isset($row['declared_starts_on'])));

    expect(array_column($periods, 'uuid'))->toBe([$this->subjectFreeze->uuid])
        // Two declared, one of them since lifted: the ledger is the only place
        // the lifted one still exists.
        ->and($ledger)->toHaveCount(2);
});

it('ages a student\'s old freezes out by their own dates, and spares a held subject and the workspace', function (): void {
    $longAgo = CarbonImmutable::now()->subYears(4);
    $held = User::factory()->create();

    DB::table('freeze_periods')->insert([
        [
            'uuid' => (string) Str::uuid(), 'workspace_id' => $this->workspaceId, 'student_user_id' => $this->classmate->getKey(),
            'starts_on' => $longAgo->toDateString(), 'ends_on' => $longAgo->addDays(5)->toDateString(),
            'created_by' => $this->subject->getKey(), 'created_at' => $longAgo, 'updated_at' => $longAgo,
        ],
        [
            'uuid' => (string) Str::uuid(), 'workspace_id' => $this->workspaceId, 'student_user_id' => $held->getKey(),
            'starts_on' => $longAgo->toDateString(), 'ends_on' => $longAgo->addDays(5)->toDateString(),
            'created_by' => $this->subject->getKey(), 'created_at' => $longAgo, 'updated_at' => $longAgo,
        ],
        [
            'uuid' => (string) Str::uuid(), 'workspace_id' => $this->workspaceId, 'student_user_id' => null,
            'starts_on' => $longAgo->toDateString(), 'ends_on' => $longAgo->addDays(5)->toDateString(),
            'created_by' => $this->subject->getKey(), 'created_at' => $longAgo, 'updated_at' => $longAgo,
        ],
    ]);

    DB::table('freeze_period_starts')->insert([
        ['workspace_id' => $this->workspaceId, 'student_user_id' => $this->classmate->getKey(), 'starts_on' => $longAgo->toDateString(), 'created_at' => $longAgo],
        ['workspace_id' => $this->workspaceId, 'student_user_id' => null, 'starts_on' => $longAgo->toDateString(), 'created_at' => $longAgo],
    ]);

    $expired = app(LiveSessionsPersonalData::class)->expire(
        'freeze_period',
        CarbonImmutable::now()->subDays(1095),
        ExpiryBehaviour::Delete,
        100,
        [(int) $held->getKey()],
    );

    // The classmate's old period and old ledger line — nothing else.
    expect($expired)->toBe(2)
        ->and(DB::table('freeze_periods')->where('student_user_id', $this->classmate->getKey())->pluck('id')->all())
        ->toBe([$this->classmateFreeze->getKey()])
        ->and(DB::table('freeze_periods')->where('student_user_id', $held->getKey())->count())->toBe(1)
        ->and(DB::table('freeze_periods')->whereNull('student_user_id')->count())->toBe(2)
        ->and(DB::table('freeze_period_starts')->whereNull('student_user_id')->count())->toBe(2);

    // And it converges: the same sweep tomorrow finds nothing.
    expect(app(LiveSessionsPersonalData::class)->expire(
        'freeze_period',
        CarbonImmutable::now()->subDays(1095),
        ExpiryBehaviour::Delete,
        100,
        [(int) $held->getKey()],
    ))->toBe(0);
});
