<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\RelationStatus;
use App\Shared\Actions\Action;

/**
 * Ends a guardian relationship (FR-023).
 *
 * Revoked, never deleted. Two reasons: the notifications already delivered under
 * this relation stay explainable, and a delete would cascade the delivery history
 * away with it. Jobs already sitting in the queue re-read the status before they
 * deliver, so "immediately" really is immediately.
 */
class RevokeRelation extends Action
{
    public function handle(ParentStudentRelation $relation): ParentStudentRelation
    {
        if ($relation->status === RelationStatus::Revoked->value) {
            return $relation;
        }

        $relation->forceFill([
            'status' => RelationStatus::Revoked->value,
            'revoked_at' => now(),
            /*
            | ⚠️ THE SENTINEL IS FREED IN THE SAME WRITE THAT ENDS THE ROW
            | (spec 030). `unique(guardian_user_id, student_user_id, live_slot)`
            | is what stops one pair holding two live links; moving `live_slot`
            | off `0` here is what lets the family ask again afterwards (FR-012).
            |
            | The row's own id, never NULL and never a constant: NULL would make
            | every revoked row for a pair collide with every other on MySQL as
            | much as it would fail to collide on SQLite, and a constant collides
            | with itself the second time a pair is cut. Same shape as
            | `pending_slot` in 021.
            |
            | `forceFill` because the column is deliberately not `$fillable` —
            | mass-assignable it would be a second way to free the pair from
            | outside this write.
            */
            'live_slot' => $relation->getKey(),
        ])->save();

        return $relation;
    }
}
