<?php

declare(strict_types=1);

namespace App\Modules\Learning\Console;

use App\Shared\Contracts\CohortDirectory;
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
 * ⚠️ **AND THE COUNT ITSELF NOW LIVES IN THE DIRECTORY, WHICH IS WHAT FR-013
 * REQUIRES OF IT.** The teacher's warning before they disable a plan counts the
 * same thing this guard counts — «والعددُ يُحسَبُ بتهجئةِ FR-002 نفسِها التي
 * يقرؤها حارسُ ما قبلَ النشر (FR-010)» — and a warning that says a number while
 * the gate does otherwise is worse than no warning. So the body moved to
 * {@see CohortDirectory::unlistedCohortsWithMembers()} and this file is one of
 * its two readers. The verdict still comes from the same contract the picker,
 * the public page and the join door ask; a guard that spelled the condition
 * again would be answering a DIFFERENT question, and would say zero while the
 * gate hid four.
 *
 * ⚠️ AND «WITH MEMBERS» IS THE WHOLE FILTER. An empty unlisted group is a group
 * nobody was ever offered and nobody is in; the release takes nothing from
 * anybody by hiding it. What matters is a room with people already in it.
 */
class CohortGateImpact extends Command
{
    protected $signature = 'cohorts:gate-impact';

    protected $description = 'عُدَّ المجموعات التي فيها أعضاء وستخرج من العرض حين تعضّ بوّابة السعر (٠٣٦ · FR-010)';

    public function handle(CohortDirectory $cohorts): int
    {
        // `null` — the platform, not one teacher: this runs before a release.
        $losing = $cohorts->unlistedCohortsWithMembers();

        if ($losing === []) {
            $this->info('صفر: لا مجموعة فيها أعضاء تخرج من العرض.');

            return self::SUCCESS;
        }

        $this->error('مجموعات فيها أعضاء ستخرج من العرض: '.count($losing));

        $this->table(
            ['المعرّف', 'الاسم', 'مساحة العمل', 'الأعضاء'],
            array_map(
                static fn (array $row): array => [
                    $row['uuid'],
                    $row['name'],
                    $row['workspace_id'],
                    $row['members'],
                ],
                array_values($losing),
            ),
        );

        /*
        | ⛔ A NON-ZERO EXIT, AND THAT IS THE DIFFERENCE BETWEEN A GUARD AND A
        | NOTICE. Printed and exiting zero, this would scroll past in a deploy
        | log and be read after the students had already lost their group.
        */
        return self::FAILURE;
    }
}
