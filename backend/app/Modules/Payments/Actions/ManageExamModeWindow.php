<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Payments\Support\BalanceAnnouncer;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Open and close the window in which nothing is deferred (FR-046).
 *
 * Exam season is the platform's peak risk: a student sits their papers, the term
 * ends, and whatever they owed goes with them. So for a period the teacher
 * declares, the floor is forced to zero — a booking needs credits in hand
 * whatever ceiling the student has earned.
 *
 * ⚠️ NOTHING IS INVALIDATED RETROACTIVELY (FR-047). Opening a window writes one
 * row; it cancels no booking and reverses no charge. A seat taken yesterday under
 * yesterday's rules stays taken, because the alternative is a teacher opening
 * exam mode and silently emptying their own timetable.
 *
 * ⚠️ AND THE RETURN IS AUTOMATIC BY CONSTRUCTION. Nothing "closes" a window when
 * its last day passes: coverage is a comparison against today, asked at each
 * booking. There is no job to forget, and no stored flag that could be left on.
 *
 * The one thing that does have to happen is telling people. Withholding is
 * derived, so the flip is instantaneous and invisible — a student who could book
 * this morning is refused this afternoon, with no movement on their balance to
 * explain it. This is the third and last of T117's dispatch sites.
 */
class ManageExamModeWindow extends Action
{
    use LogsActivity;

    public function __construct(private readonly BalanceAnnouncer $announcer) {}

    public function handle(
        Workspace $workspace,
        ?CarbonImmutable $startsOn = null,
        ?CarbonImmutable $endsOn = null,
        ?User $performedBy = null,
    ): ?ExamModeWindow {
        // Closing: every window covering today. Not "the one with this uuid" —
        // the teacher's question is "turn exam mode off", and answering it with a
        // single row would leave a second overlapping window quietly in force.
        if ($startsOn === null || $endsOn === null) {
            return $this->flipAround($workspace, function () use ($workspace): null {
                $closed = ExamModeWindow::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->covering(now())
                    ->get();

                foreach ($closed as $window) {
                    $this->logActivity('exam_mode.closed', $window, [
                        'starts_on' => $window->starts_on->toDateString(),
                        'ends_on' => $window->ends_on->toDateString(),
                    ]);

                    $window->delete();
                }

                return null;
            });
        }

        if ($endsOn->lt($startsOn)) {
            throw new DomainException('تاريخ نهاية النافذة قبل بدايتها.');
        }

        return $this->flipAround($workspace, function () use ($workspace, $startsOn, $endsOn, $performedBy): ExamModeWindow {
            $window = ExamModeWindow::query()->create([
                'workspace_id' => $workspace->getKey(),
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'created_by' => $performedBy?->getKey(),
            ]);

            $this->logActivity('exam_mode.opened', $window, [
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
            ]);

            return $window;
        });
    }

    /**
     * Run the write, and tell everyone whose standing it moved (FR-033 · T117).
     *
     * The before-state is taken BEFORE the write and held, exactly as
     * {@see BalanceAnnouncer} requires — computed afterwards it would be the
     * state they are in NOW, and every transition would be invisible.
     *
     * Read in bulk on both sides. The per-balance form of the predicate resolves
     * the mode and the exam window per call, so announcing a whole workspace
     * through it would be two queries per student on a screen a teacher opens
     * twice a year.
     *
     * @template T of ExamModeWindow|null
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function flipAround(Workspace $workspace, callable $write): ?ExamModeWindow
    {
        $before = $this->standings($workspace);

        $result = $write();

        // ⚠️ THE SECOND `balancesOf()` IS DELIBERATE, not a query nobody noticed.
        // `stamp()` writes `is_withheld` onto the instances it is given, so
        // handing it the same collection the before-state was read from would
        // overwrite the very answers being compared against — and every
        // transition would read as "no change".
        $this->announcer->announceStandingChanges($this->balancesOf($workspace), $before);

        return $result;
    }

    /**
     * Who was withheld before the write, keyed by balance id.
     *
     * @return array<int, bool>
     */
    private function standings(Workspace $workspace): array
    {
        return $this->announcer
            ->standingsFor($this->balancesOf($workspace));
    }

    /** @return Collection<int, CreditBalance> */
    private function balancesOf(Workspace $workspace): Collection
    {
        return CreditBalance::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->get();
    }
}
