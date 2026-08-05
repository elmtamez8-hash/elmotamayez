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
        ])->save();

        return $relation;
    }
}
