<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\PlatformStaffResource\Pages;

use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Tenancy\Filament\Resources\PlatformStaffResource;
use App\Modules\Tenancy\Models\PlatformStaff;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlatformStaff extends CreateRecord
{
    protected static string $resource = PlatformStaffResource::class;

    /**
     * The grantor is the person signed in, never a field.
     *
     * A `assigned_by` select would be a screen where an admin records somebody
     * else as the author of their own decision — which is the one column on this
     * table that must be a fact rather than a claim.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['assigned_by'] = (int) Filament::auth()->id();

        /** @var PlatformStaff $staff */
        $staff = PlatformStaff::query()->create($data);

        /*
         * ⚠️ THE MANDATE HAD TWO CALLERS AND BOTH WERE WORKSPACE PATHS —
         * accepting an invitation and creating a workspace. So platform standing,
         * which is the widest privilege the product grants, was the one grant that
         * never started a two-factor clock. Measured on production 2026-09-03: the
         * platform's only super admin had no `user_security_settings` row at all,
         * which means `RequireTwoFactor` passed them unconditionally and the
         * `2fa.required` on the two approval routes was guarding nobody.
         *
         * `applyTo()` is idempotent, so a second standing keeps the first
         * deadline rather than handing out a fresh grace period.
         */
        TwoFactorMandate::applyTo($staff->user);

        // The directory memoises per request; without this the page rendered
        // straight afterwards answers from before the grant existed.
        PlatformStaffResource::forgetStanding($staff);

        return $staff;
    }
}
