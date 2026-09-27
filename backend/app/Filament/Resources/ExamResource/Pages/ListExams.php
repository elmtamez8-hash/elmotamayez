<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ⚠️ NO CREATE BUTTON. An exam is written by its teacher on the site
 * (`/manage`), where `SyncExamItems` and the bank's rules apply. A modal create
 * here is Filament's `new Exam($data)`: it stamps the OFFICER's own workspace
 * (`BelongsToWorkspace` fills it from their `last_workspace_id`) and reaches no
 * Action at all.
 */
class ListExams extends ListRecords
{
    protected static string $resource = ExamResource::class;
}
