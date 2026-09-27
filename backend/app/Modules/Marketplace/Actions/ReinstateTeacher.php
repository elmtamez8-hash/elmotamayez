<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;

class ReinstateTeacher extends Action
{
    /**
     * ⚠️ `marketplace.teachers.approve`, not `…suspend`: the only door here is
     * the profile screen's «اعتماد» button, which reinstates when there is no
     * application left to approve — lifting a suspension is putting a teacher
     * back on the marketplace, the approver's act.
     */
    public function handle(TeacherProfile $teacher, User $by): TeacherProfile
    {
        if (! $by->can(Permissions::MARKETPLACE_TEACHERS_APPROVE)) {
            throw new AuthorizationException('إعادةُ المدرّسِ إلى السوقِ قرارُ المنصّة.');
        }

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
