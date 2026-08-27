<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Support\CohortRefusal;
use App\Shared\Actions\Action;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Rename a group, describe it, resize it, or close it to new joins.
 *
 * ⚠️ AN ARCHIVED GROUP IS TERMINAL AND CANNOT BE EDITED BACK OPEN. `status` is
 * not fillable for exactly this reason: a PATCH body carrying `status: open`
 * would otherwise resurrect a run the teacher deliberately ended, and every
 * closed membership on it would suddenly be history of a live group.
 *
 * ⚠️ AND A CAPACITY BELOW THE CURRENT HEADCOUNT IS ACCEPTED. It means "no more
 * people", not "throw somebody out": nobody is removed, the group simply reads
 * full until it shrinks by itself. Refusing it would leave a teacher who
 * over-filled a room with no way to stop it filling further.
 */
class UpdateCohort extends Action
{
    /** @param  array{name?: string, description?: string|null, capacity?: int|null, status?: string}  $attributes */
    public function handle(Cohort $cohort, array $attributes): Cohort
    {
        if ($cohort->status === Cohort::ARCHIVED) {
            throw new CohortRefusal('cohort_archived', 'هذه المجموعة مؤرشفة ولا تُعدَّل.');
        }

        $status = $attributes['status'] ?? null;

        if ($status !== null && ! in_array($status, [Cohort::OPEN, Cohort::CLOSED], true)) {
            // Archiving is its own Action, because it also has to settle the
            // requests aimed at the group — a status write here would leave them
            // pending on something nobody can approve.
            throw new CohortRefusal('invalid_status', 'حالة غير مقبولة — الأرشفة لها إجراؤها الخاصّ.');
        }

        try {
            $cohort->fill(array_intersect_key($attributes, array_flip(['name', 'description', 'capacity'])));

            if ($status !== null) {
                $cohort->status = $status;
            }

            $cohort->save();
        } catch (UniqueConstraintViolationException) {
            throw new CohortRefusal('duplicate_name', 'يوجد في هذا الكورس مجموعة بهذا الاسم.');
        }

        return $cohort->refresh();
    }
}
