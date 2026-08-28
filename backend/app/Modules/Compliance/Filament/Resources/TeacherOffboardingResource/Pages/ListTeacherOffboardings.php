<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources\TeacherOffboardingResource\Pages;

use App\Modules\Compliance\Filament\Resources\TeacherOffboardingResource;
use Filament\Resources\Pages\ListRecords;

class ListTeacherOffboardings extends ListRecords
{
    protected static string $resource = TeacherOffboardingResource::class;
}
