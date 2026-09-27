<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;

class SuspendTeacher extends Action
{
    /**
     * ⚠️ The actor is a required argument and the permission is asked HERE, not
     * only by the button's `visible()`: a hidden button is not a guard, and this
     * Action takes a teacher off the marketplace in one statement.
     */
    public function handle(TeacherProfile $teacher, User $by): TeacherProfile
    {
        if (! $by->can(Permissions::MARKETPLACE_TEACHERS_SUSPEND)) {
            throw new AuthorizationException('إيقافُ المدرّسِ قرارُ المنصّة.');
        }

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
