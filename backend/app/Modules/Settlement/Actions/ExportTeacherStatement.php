<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Data\TeacherStatement;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\TeacherFieldAllowlist;
use App\Shared\Actions\Action;

/**
 * The same statement, as a file (FR-021).
 *
 * It calls `BuildTeacherStatement` rather than repeating its queries, and its
 * columns come from `TeacherFieldAllowlist` rather than a list of its own. Both
 * are the same decision: the export is the surface everybody forgets when they
 * add a field — it is generated once and opened in Excel, where no reviewer ever
 * looks at it — so it must not be able to carry a field the API does not.
 *
 * Machine keys rather than Arabic headers, deliberately: an Arabic header row
 * would be a second list, and a second list is one that diverges. The teacher
 * reads Arabic on the screen; the file is for their accountant.
 *
 * The unit rows stream with `lazy()`: an export is the one place a teacher's
 * whole year arrives at once, and materialising ten thousand models to write
 * them out one at a time is the memory spike nobody sees until the year is over.
 */
class ExportTeacherStatement extends Action
{
    public function __construct(
        private readonly BuildTeacherStatement $build,
    ) {}

    public function handle(TeacherProfile $teacher): string
    {
        $statement = $this->build->handle($teacher);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            // Unreachable in practice; typed rather than assumed, because the
            // alternative is an fputcsv() on false further down.
            return '';
        }

        // A UTF-8 BOM. Without it Excel on Windows reads Arabic column values as
        // mojibake, and the teacher's first act is to email a screenshot of it.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($this->summaryRows($statement) as $row) {
            fputcsv($handle, $row);
        }

        // A blank line between the summary and the ledger of units — one file,
        // two shapes, which is how every accounting export reads.
        fputcsv($handle, []);
        fputcsv($handle, TeacherFieldAllowlist::EXPORT_COLUMNS);

        $units = TeachingUnit::query()
            ->where('teacher_profile_id', $teacher->getKey())
            ->whereNull('settlement_period_id')
            ->orderBy('delivered_at')
            ->lazy();

        foreach ($units as $unit) {
            fputcsv($handle, $this->unitRow($unit));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The summary as key/value pairs, every key drawn from the allowlist.
     *
     * @return list<list<string>>
     */
    private function summaryRows(TeacherStatement $statement): array
    {
        $rows = [
            ['key', 'value'],
            ['starts_on', $statement->startsOn],
            ['ends_on', $statement->endsOn],
            ['status', $statement->status->value],
            ['next_payout_on', $statement->endsOn],
            ['students_count', (string) $statement->studentsCount],
            ['currency', $statement->currency],
        ];

        foreach ($statement->unitCounts as $status => $count) {
            $rows[] = [$status, (string) $count];
        }

        $rows[] = ['carried_in_minor', (string) $statement->carriedInMinor];
        $rows[] = ['gross_minor', (string) $statement->grossMinor];

        foreach ($statement->deductions as $line) {
            // The type, not the word "deduction": a reversal and a manual
            // deduction move the same total for entirely different reasons, and
            // an export that flattens them cannot be argued with.
            $rows[] = [$line['type'], (string) $line['amount_minor'], $line['reason'] ?? ''];
        }

        $rows[] = ['net_minor', (string) $statement->netMinor];

        return $rows;
    }

    /** @return list<string> */
    private function unitRow(TeachingUnit $unit): array
    {
        $values = [
            'delivered_at' => $unit->delivered_at->toDateString(),
            'session_type' => $unit->session_type->value,
            'status' => $unit->status->value,
            'basis' => $unit->basis->value,
            'frozen_seats' => (string) $unit->frozen_seats,
            'amount_minor' => (string) $unit->amount_minor,
            'currency' => (string) $unit->currency,
        ];

        // Ordered by the allowlist, not by this array: the column order is part
        // of the shared contract, and a row assembled in its own order lines up
        // with the wrong header the moment a column is added.
        return array_map(
            static fn (string $column): string => $values[$column],
            TeacherFieldAllowlist::EXPORT_COLUMNS,
        );
    }
}
