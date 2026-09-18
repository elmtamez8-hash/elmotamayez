<?php

declare(strict_types=1);

namespace App\Modules\Learning\Console;

use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Support\CohortPricing;
use Illuminate\Console\Command;

/**
 * ٠٣٦ · FR-010 — who loses something the moment the price gate bites.
 *
 * ⛔ A COMMAND, NOT A LINE IN A DOCUMENT. The spec carries a measurement taken on
 * 2026-09-16 that answered ZERO, and that number is **fragile by construction**:
 * the live groups all inherit ONE workspace-wide plan, so narrowing that plan to
 * a single course drops the coverage off a group without anybody touching the
 * group. A figure from two days ago is not the figure at the moment of the
 * release, and this is the difference between the two.
 *
 * ⛔ AND IT WRITES NOTHING. A guard that repairs what it finds hides the very
 * thing it was run to show — the operator would read «zero» over a fix nobody
 * decided on. Read-only, and a non-zero exit stops the release rather than
 * reporting after it.
 *
 * ⚠️ IT READS THE SAME SPELLING THE GATE READS. The verdict comes from
 * {@see CohortPricing}, which asks the same contract the picker, the public page
 * and the join door ask. A guard that spelled the condition again would be
 * answering a DIFFERENT question — and would say zero while the gate hid four.
 *
 * ⚠️ AND «WITH MEMBERS» IS THE WHOLE FILTER. An empty unlisted group is a group
 * nobody was ever offered and nobody is in; the release takes nothing from
 * anybody by hiding it. What matters is a room with people already in it.
 */
class CohortGateImpact extends Command
{
    protected $signature = 'cohorts:gate-impact';

    protected $description = 'عُدَّ المجموعات التي فيها أعضاء وستخرج من العرض حين تعضّ بوّابة السعر (٠٣٦ · FR-010)';

    public function handle(CohortPricing $pricing): int
    {
        /*
        | The candidate set is the OFFER's own: group cohorts that are not
        | archived. An archived group is already out of every list, so counting
        | one would report an impact the release does not cause.
        */
        $cohorts = Cohort::query()
            ->withoutWorkspaceScope()
            ->group()
            ->where('status', '!=', Cohort::ARCHIVED)
            ->get();

        if ($cohorts->isEmpty()) {
            $this->info('لا توجد مجموعات معروضة على المنصّة أصلاً.');

            return self::SUCCESS;
        }

        // One query for every group's membership count, rather than one per row.
        $withMembers = CohortMembership::query()
            ->withoutWorkspaceScope()
            ->whereNull('closed_at')
            ->whereIn('cohort_id', $cohorts->modelKeys())
            ->select('cohort_id')
            ->selectRaw('count(*) as members')
            ->groupBy('cohort_id')
            ->pluck('members', 'cohort_id');

        $losing = [];

        foreach ($pricing->stamp($cohorts) as $cohort) {
            $members = (int) ($withMembers[$cohort->getKey()] ?? 0);

            if ($members === 0 || $cohort->priceReaches()) {
                continue;
            }

            $losing[] = [
                (string) $cohort->uuid,
                (string) $cohort->name,
                (int) $cohort->workspace_id,
                $members,
            ];
        }

        if ($losing === []) {
            $this->info('صفر: لا مجموعة فيها أعضاء تخرج من العرض.');

            return self::SUCCESS;
        }

        $this->error('مجموعات فيها أعضاء ستخرج من العرض: '.count($losing));
        $this->table(['المعرّف', 'الاسم', 'مساحة العمل', 'الأعضاء'], $losing);

        /*
        | ⛔ A NON-ZERO EXIT, AND THAT IS THE DIFFERENCE BETWEEN A GUARD AND A
        | NOTICE. Printed and exiting zero, this would scroll past in a deploy
        | log and be read after the students had already lost their group.
        */
        return self::FAILURE;
    }
}
