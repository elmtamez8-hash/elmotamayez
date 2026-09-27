<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use App\Modules\Assessments\Actions\ChangeExamStatus;
use App\Modules\Assessments\Enums\ExamStatus;
use App\Modules\Assessments\Models\Exam;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditExam extends EditRecord
{
    protected static string $resource = ExamResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            /*
            | The refusal itself lives in `Exam::booted()` — this only turns it
            | into a sentence on the screen instead of the panel's error page.
            */
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, Exam $record): void {
                    $refusal = $record->deletionRefusal();

                    if ($refusal !== null) {
                        Notification::make()->danger()->title($refusal)->send();
                        $action->cancel();
                    }
                }),
        ];
    }

    /**
     * ⚠️ `status` NEVER REACHES `update()` — it goes through
     * {@see ChangeExamStatus}, in the same transaction as the rest of the form,
     * exactly as `EditCourse` does for a course. Filament's default is
     * `$record->update($data)`, which published and archived papers with no
     * entry in the activity log.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Exam $record */
        $status = is_string($data['status'] ?? null) ? ExamStatus::tryFrom($data['status']) : null;
        unset($data['status']);

        return DB::transaction(function () use ($record, $data, $status): Exam {
            $record->update($data);

            if ($status !== null) {
                app(ChangeExamStatus::class)->handle($record, $status);
            }

            return $record->refresh();
        });
    }
}
