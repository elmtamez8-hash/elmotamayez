<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources\BreachReportResource\Pages;

use App\Modules\Compliance\Filament\Resources\BreachReportResource;
use Filament\Resources\Pages\ListRecords;

class ListBreachReports extends ListRecords
{
    protected static string $resource = BreachReportResource::class;
}
