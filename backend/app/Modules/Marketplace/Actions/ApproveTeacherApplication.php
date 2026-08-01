<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A human decision by the academic team (FR-016), recorded with who and when.
 */
class ApproveTeacherApplication extends Action
{
    public function handle(TeacherApplication $application, User $reviewer): TeacherProfile
    {
        $profile = $application->teacherProfile;

        if ($profile === null) {
            throw new DomainException('لا يوجد ملف مدرّس مرتبط بهذا الطلب.');
        }

        return DB::transaction(function () use ($application, $profile, $reviewer): TeacherProfile {
            $profile->forceFill([
                'approval_status' => TeacherProfile::STATUS_APPROVED,
                'is_publicly_listed' => self::derivePublicListing($profile),
            ])->save();

            $application->forceFill([
                'status' => TeacherApplication::STATUS_APPROVED,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            // Within a minute of the decision the teacher is on the marketplace
            // (SC-010) — the listing cache is the only thing standing in the way.
            MarketplaceCache::flush();

            event(new TeacherApproved($profile));

            return $profile;
        });
    }

    /**
     * Derived from (approved × workspace participates), never assigned by hand.
     *
     * Approving a teacher in a workspace that has not opted into the marketplace
     * succeeds and leaves them unlisted. That is correct: the academy decides
     * whether its teachers are offered publicly, the reviewer decides whether this
     * person may teach.
     */
    private static function derivePublicListing(TeacherProfile $profile): bool
    {
        return (bool) $profile->workspace?->participates_in_marketplace;
    }
}
