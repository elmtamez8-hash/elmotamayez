<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\RegionResource\Pages;

use App\Modules\Marketplace\Filament\Resources\RegionResource;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditRegion extends EditRecord
{
    protected static string $resource = RegionResource::class;

    /**
     * No DeleteAction, deliberately — {@see TaxonomyPolicy::delete()} and
     * {@see RegionResource}. `student_profiles.region_id` names this row with no
     * foreign key behind it; retiring it is the toggle.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
