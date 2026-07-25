<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Convenience trait for logging activity via spatie/activitylog.
 * Provides a single `logActivity()` method that sets causer, subject, and workspace.
 */
trait LogsActivity
{
    /**
     * @param  array<string, mixed>  $properties
     */
    protected function logActivity(string $description, ?Model $subject = null, array $properties = []): void
    {
        $activity = activity()
            ->withProperties(array_merge([
                'workspace_id' => app(WorkspaceContext::class)->id(),
            ], $properties));

        $user = Auth::user();

        if ($user instanceof Model) {
            $activity->causedBy($user);
        }

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        $activity->log($description);
    }
}
