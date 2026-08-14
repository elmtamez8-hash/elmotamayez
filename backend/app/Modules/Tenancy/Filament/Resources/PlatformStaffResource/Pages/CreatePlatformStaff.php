<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\PlatformStaffResource\Pages;

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

        // The directory memoises per request; without this the page rendered
        // straight afterwards answers from before the grant existed.
        PlatformStaffResource::forgetStanding($staff);

        return $staff;
    }
}
