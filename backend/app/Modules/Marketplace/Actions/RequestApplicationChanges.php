<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Events\TeacherChangesRequested;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Shared\Actions\Action;

/**
 * Reopen the wizard with a note, rather than closing the door (FR-072).
 *
 * The profile keeps its pending status: nothing was decided, so nothing about
 * the teacher's standing changes while they fix the application.
 */
class RequestApplicationChanges extends Action
{
    public function handle(TeacherApplication $application, User $reviewer, string $reason): TeacherApplication
    {
        $application->forceFill([
            'status' => TeacherApplication::STATUS_CHANGES_REQUESTED,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        event(new TeacherChangesRequested($application, $reason));

        return $application;
    }
}
