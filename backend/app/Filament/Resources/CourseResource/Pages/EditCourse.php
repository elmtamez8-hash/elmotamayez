<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Resources\CourseResource;
use App\Modules\Courses\Actions\ChangeCourseStatus;
use App\Modules\Courses\Enums\CourseStatus;
use App\Modules\Courses\Models\Course;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * ⚠️ `status` NEVER REACHES `update()`. Filament's default here is
     * `$record->update($data)`, which published, unpublished and archived
     * courses with no entry in the activity log — the API's `/publish` writes
     * one through `PublishCourse`, and the panel is the only other door that
     * moves the column. The rest of the form is saved as before; the status goes
     * through {@see ChangeCourseStatus}, in the same transaction, so a failure
     * there does not leave the other fields saved without it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Course $record */
        $status = is_string($data['status'] ?? null) ? CourseStatus::tryFrom($data['status']) : null;
        unset($data['status']);

        return DB::transaction(function () use ($record, $data, $status): Course {
            $record->update($data);

            if ($status !== null) {
                app(ChangeCourseStatus::class)->handle($record, $status);
            }

            return $record->refresh();
        });
    }
}
