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
     * ⚠️ EITHER PERMISSION, BECAUSE THE TWO DOORS ASK DIFFERENT ONES — and this
     * re-check must not refuse what a door already admits. The API's
     * `/reinstate` asks `marketplace.teachers.suspend` (lifting a suspension is
     * the suspender's act); the profile screen reaches here through its
     * «اعتماد» button, gated on `marketplace.teachers.approve`, when there is no
     * application left to approve. Somebody holding neither is refused.
     */
    public function handle(TeacherProfile $teacher, User $by): TeacherProfile
    {
        if (! $by->can(Permissions::MARKETPLACE_TEACHERS_SUSPEND)
            && ! $by->can(Permissions::MARKETPLACE_TEACHERS_APPROVE)) {
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
