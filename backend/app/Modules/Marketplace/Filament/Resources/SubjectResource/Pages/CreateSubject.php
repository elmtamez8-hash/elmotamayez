<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\SubjectResource\Pages;

use App\Modules\Marketplace\Filament\Resources\SubjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSubject extends CreateRecord
{
    protected static string $resource = SubjectResource::class;
}
