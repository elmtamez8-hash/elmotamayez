<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\SchoolYearResource\Pages;

use App\Modules\Marketplace\Filament\Resources\SchoolYearResource;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use App\Shared\Traits\EditsTranslatableRecord;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditSchoolYear extends EditRecord
{
    use EditsTranslatableRecord;

    protected static string $resource = SchoolYearResource::class;

    /**
     * No DeleteAction, deliberately — {@see TaxonomyPolicy::delete()} and
     * {@see SchoolYearResource}. Two tables name this row as TEXT with no
     * foreign key behind either; retiring it is the toggle.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
