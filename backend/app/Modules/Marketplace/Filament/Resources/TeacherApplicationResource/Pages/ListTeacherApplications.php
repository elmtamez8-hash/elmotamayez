<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TeacherApplicationResource\Pages;

use App\Modules\Marketplace\Filament\Resources\TeacherApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListTeacherApplications extends ListRecords
{
    protected static string $resource = TeacherApplicationResource::class;
}
