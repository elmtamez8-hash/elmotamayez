<?php

declare(strict_types=1);

namespace App\Filament\Resources\MessageTemplateResource\Pages;

use App\Filament\Resources\MessageTemplateResource;
use App\Shared\Traits\EditsTranslatableRecord;
use Filament\Resources\Pages\EditRecord;

class EditMessageTemplate extends EditRecord
{
    use EditsTranslatableRecord;

    protected static string $resource = MessageTemplateResource::class;
}
