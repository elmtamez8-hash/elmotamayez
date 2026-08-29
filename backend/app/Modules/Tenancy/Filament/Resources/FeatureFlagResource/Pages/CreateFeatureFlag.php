<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\FeatureFlagResource\Pages;

use App\Modules\Tenancy\Filament\Resources\FeatureFlagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeatureFlag extends CreateRecord
{
    protected static string $resource = FeatureFlagResource::class;
}
