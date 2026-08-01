<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;

class SuspendTeacher extends Action
{
    public function handle(TeacherProfile $teacher): TeacherProfile
    {
        $teacher->forceFill([
            'approval_status' => TeacherProfile::STATUS_SUSPENDED,
            'is_publicly_listed' => false,
        ])->save();

        // Suspension is the case where a stale cache does the most damage, so the
        // flush is not deferred to the next natural expiry (SC-010).
        MarketplaceCache::flush();

        return $teacher;
    }
}
