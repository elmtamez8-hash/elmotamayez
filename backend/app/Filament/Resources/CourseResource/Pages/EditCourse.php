<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Resources\CourseResource;
use App\Modules\Courses\Models\Course;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            /*
            | The refusal itself lives in `Course::booted()` — this only turns it
            | into a sentence on the screen instead of the panel's error page.
            */
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, Course $record): void {
                    $refusal = $record->deletionRefusal();

                    if ($refusal !== null) {
                        Notification::make()->danger()->title($refusal)->send();
                        $action->cancel();
                    }
                }),
        ];
    }
}
