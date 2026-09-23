<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\CloseSettlementPeriod;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Jobs\CloseDueSettlementPeriodsJob;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\SettlementWindow;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
| One teacher whose close throws must not stop every close behind it.
|
| ⚠️ THE POISON TEACHER IS CREATED FIRST, AND THAT IS THE WHOLE TEST. The walk is
| ordered by (workspace_id, teacher_profile_id), so the lower id is met first; a
| poison row created second would be reached after the healthy teacher had
| already closed, and the case would pass against a job with no catch at all.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

/** A period that ended yesterday, with one accrued unit and its ledger line. */
function closeDuePoisonPeriod(TeacherProfile $teacher): SettlementPeriod
{
    $startsOn = CarbonImmutable::now()->subDays(30)->startOfDay();

    $period = SettlementPeriod::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'starts_on' => $startsOn,
        'ends_on' => $startsOn->addDays(29),
        'status' => SettlementPeriodStatus::Open,
    ]);

    $unit = TeachingUnit::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'amount_minor' => 5000,
        'delivered_at' => $startsOn->addDay(),
    ]);

    LedgerEntry::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'teaching_unit_id' => $unit->getKey(),
        'amount_minor' => 5000,
    ]);

    return $period;
}

it('closes every other teacher when one teacher\'s close throws, and logs the class alone', function (): void {
    // First: the lower id, so the walk meets it before the healthy teacher.
    $poison = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $healthy = TeacherProfile::factory()->create(['user_id' => User::factory()->create()->getKey()]);

    expect($poison->getKey())->toBeLessThan($healthy->getKey());

    $poisonPeriod = closeDuePoisonPeriod($poison);
    $healthyPeriod = closeDuePoisonPeriod($healthy);

    $close = new class(app(SettlementWindow::class), (int) $poison->getKey()) extends CloseSettlementPeriod
    {
        public function __construct(SettlementWindow $window, private readonly int $poisonTeacherId)
        {
            parent::__construct($window);
        }

        public function handle(SettlementPeriod $period, ?User $by = null): ?SettlementPeriod
        {
            if ((int) $period->teacher_profile_id === $this->poisonTeacherId) {
                // An ASCII sentinel standing in for a query's bindings.
                throw new RuntimeException('POISON-BINDING-SENTINEL');
            }

            return parent::handle($period, $by);
        }
    };

    $lines = [];
    Log::listen(function (MessageLogged $logged) use (&$lines): void {
        $lines[] = $logged;
    });

    $thrown = null;

    try {
        app(CloseDueSettlementPeriodsJob::class)->handle(
            app(WorkspaceContext::class),
            $close,
            app(SettlementWindow::class),
        );
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    // The healthy teacher's window closed although the walk met the poison first.
    expect($healthyPeriod->fresh()->status)->toBe(SettlementPeriodStatus::Closed)
        ->and($poisonPeriod->fresh()->status)->toBe(SettlementPeriodStatus::Open);

    // The failure is not swallowed: it is rethrown after the walk, so the full
    // message lands in failed_jobs rather than disappearing.
    expect($thrown?->getMessage())->toBe('POISON-BINDING-SENTINEL');

    $failure = collect($lines)->first(fn (MessageLogged $l): bool => $l->message === 'settlement.close_due.teacher_failed');

    expect($failure)->not->toBeNull()
        ->and($failure->context)->toBe([
            'workspace_id' => (int) $this->workspace->getKey(),
            'teacher_profile_id' => (int) $poison->getKey(),
            'exception' => RuntimeException::class,
        ])
        ->and(json_encode(collect($lines)->map(fn (MessageLogged $l): array => [$l->message, $l->context])->all()))
        ->not->toContain('POISON-BINDING-SENTINEL');
});
