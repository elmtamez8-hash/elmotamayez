<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Support\CountedNoun;
use DomainException;

/**
 * «هذا التعديل يُخرِج مجموعة فيها طلاب من العرض» (٠٣٦ · FR-013).
 *
 * ⛔ **A REFUSAL RATHER THAN A REPORT, BECAUSE FR-013 SAYS «قبلَ التنفيذِ لا
 * بعدَه».** The teacher is told when their own act becomes the reason a group
 * disappears, and told in time to decide otherwise — a toast after the write
 * saying «ثلاث مجموعات خرجت للتوّ» is the thing that requirement rejects. So
 * the save runs for real inside a transaction, the count is read from the gate
 * itself, and this exception rolls the whole thing back with nothing written.
 *
 * ⚠️ **AND IT IS NOT A `SavePlan` GUARD LIKE THE ONES ABOVE IT.** The price
 * refusal and the moved-shape refusal are NOs: there is no acknowledgement that
 * makes them legal. This one is a question — a teacher is entitled to stop
 * selling a plan, and FR-013 asks only that they know what it costs. Sending
 * `acknowledge_hidden_cohorts` is the answer, and the second request is the one
 * that writes.
 *
 * ⚠️ **THE NAMES TRAVEL, NOT ONLY THE NUMBER.** «مجموعتان ستخرجان من العرض»
 * leaves the teacher to guess which, on the screen where the remedy is to price
 * or re-enable one particular plan; the list is what makes the warning
 * actionable rather than alarming.
 *
 * ponytail: the world may move between the warning and the acknowledgement — a
 * second officer pricing a plan in the same minute makes the second request
 * write something slightly different from what the first described. It is not
 * guarded, because the second request re-runs the same gate and the write it
 * performs is always the one the teacher asked for; only the sentence they read
 * could be stale.
 */
class PlanWouldHideCohorts extends DomainException
{
    /**
     * @param  array<int, array{uuid: string, name: string, workspace_id: int, members: int}>  $cohorts
     *                                                                                                   keyed by cohort id
     */
    public function __construct(private readonly array $cohorts)
    {
        parent::__construct(self::sentence($cohorts));
    }

    /**
     * @return array<int, array{uuid: string, name: string, workspace_id: int, members: int}>
     */
    public function cohorts(): array
    {
        return $this->cohorts;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_map(
            static fn (array $cohort): string => $cohort['name'],
            $this->cohorts,
        ));
    }

    /**
     * ⚠️ THE COUNTED NOUN GOES THROUGH {@see CountedNoun}, never a template
     * literal. Arabic agrees the noun in five bands, so «٢ مجموعات» is wrong at
     * two and «١١ مجموعات» is wrong at eleven — and this sentence is the one the
     * teacher reads at the moment they are deciding.
     *
     * @param  array<int, array{uuid: string, name: string, workspace_id: int, members: int}>  $cohorts
     */
    private static function sentence(array $cohorts): string
    {
        $count = CountedNoun::of(count($cohorts), [
            'one' => 'مجموعة واحدة فيها طلاب',
            'two' => 'مجموعتان فيهما طلاب',
            'few' => 'مجموعات فيها طلاب',
            'many' => 'مجموعةً فيها طلاب',
            'other' => 'مجموعة فيها طلاب',
        ]);

        $names = implode('، ', array_map(
            static fn (array $cohort): string => $cohort['name'],
            $cohorts,
        ));

        return 'هذا التعديل يُخرِج '.$count.' من العرض: '.$names.'. '
            .'لن يُكتَب شيء حتى تؤكّد.';
    }
}
