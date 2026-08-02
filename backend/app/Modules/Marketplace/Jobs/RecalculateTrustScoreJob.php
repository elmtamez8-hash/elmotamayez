<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Actions\RecalculateTrustScore;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateTrustScoreJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $teacherProfileId) {}

    public function handle(WorkspaceContext $context, RecalculateTrustScore $action): void
    {
        $teacher = TeacherProfile::query()
            ->withoutWorkspaceScope()
            ->find($this->teacherProfileId);

        if ($teacher === null) {
            return;
        }

        $workspace = $teacher->workspace;

        if ($workspace === null) {
            return;
        }

        // forWorkspace(), never set(). WorkspaceContext is an application-wide
        // singleton that caches its resolution, so a set() here would leak this
        // teacher's workspace into whatever job the same worker picks up next
        // (Constitution I).
        $context->forWorkspace($workspace, fn () => $action->handle($teacher));
    }
}
