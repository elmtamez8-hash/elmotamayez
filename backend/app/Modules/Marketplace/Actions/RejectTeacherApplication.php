<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Events\TeacherRejected;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

class RejectTeacherApplication extends Action
{
    public function handle(TeacherApplication $application, User $reviewer, string $reason): TeacherApplication
    {
        return DB::transaction(function () use ($application, $reviewer, $reason): TeacherApplication {
            $application->teacherProfile?->forceFill([
                'approval_status' => TeacherProfile::STATUS_REJECTED,
                'is_publicly_listed' => false,
            ])->save();

            $application->forceFill([
                'status' => TeacherApplication::STATUS_REJECTED,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            MarketplaceCache::flush();

            event(new TeacherRejected($application, $reason));

            return $application;
        });
    }
}
