<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;

class ReinstateTeacher extends Action
{
    public function handle(TeacherProfile $teacher): TeacherProfile
    {
        $teacher->forceFill([
            'approval_status' => TeacherProfile::STATUS_APPROVED,
            // Same derivation as approval: reinstating inside a workspace that has
            // since left the marketplace restores the teacher, not the listing.
            'is_publicly_listed' => (bool) $teacher->workspace?->participates_in_marketplace,
        ])->save();

        MarketplaceCache::flush();

        return $teacher;
    }
}
