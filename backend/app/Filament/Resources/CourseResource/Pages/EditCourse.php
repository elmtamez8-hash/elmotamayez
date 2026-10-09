<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Resources\CourseResource;
use App\Modules\Courses\Actions\ChangeCourseStatus;
use App\Modules\Courses\Actions\SetCourseTrialLesson;
use App\Modules\Courses\DTOs\SetTrialLessonData;
use App\Modules\Courses\Enums\CourseStatus;
use App\Modules\Courses\Models\Course;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        /*
        | ⚠️ The form binds `price` — the model's major-unit attribute over
        | `price_minor` — and `price` is NOT `$fillable` (the API's course writes
        | name `price_minor`, and a second mass-assignable spelling of one column
        | is a second door to it). So it is assigned here explicitly, and the
        | model's setter stores minor units; left in `$data`, `update()` would
        | DISCARD it in silence and the toast would still say «تم الحفظ».
        */
        if (array_key_exists('price', $data)) {
            $record->price = $data['price'];
            unset($data['price']);
        }

        /*
        | Spec 040 — virtual, so `update()` never sees it (the column is not
        | fillable and would be dropped in silence). Absent when the field was
        | disabled for this reader; only a CHANGE goes to the Action.
        */
        $trialSent = array_key_exists('trial_lesson_uuid', $data)
            && (auth()->user()?->can('chooseTrialLesson', $record) ?? false);
        $trial = is_string($data['trial_lesson_uuid'] ?? null) ? $data['trial_lesson_uuid'] : null;
        unset($data['trial_lesson_uuid']);
        $currentTrial = $record->trialLesson?->uuid;

        return DB::transaction(function () use ($record, $data, $status, $trialSent, $trial, $currentTrial): Course {
            $record->update($data);

            if ($status !== null) {
                app(ChangeCourseStatus::class)->handle($record, $status);
            }

            if ($trialSent && $trial !== $currentTrial) {
                try {
                    app(SetCourseTrialLesson::class)->handle($record, new SetTrialLessonData($trial, $currentTrial));
                } catch (ValidationException $refused) {
                    // The Action names its field `lesson`; the form's is this one.
                    throw ValidationException::withMessages([
                        'data.trial_lesson_uuid' => $refused->errors()['lesson'] ?? $refused->getMessage(),
                    ]);
                }
            }

            return $record->refresh();
        });
    }
}
