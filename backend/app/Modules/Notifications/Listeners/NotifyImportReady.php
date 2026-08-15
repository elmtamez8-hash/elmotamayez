<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Assessments\Enums\ImportStatus;
use App\Modules\Assessments\Events\QuestionImported;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the teacher their upload is done, and where the report is.
 *
 * The teacher closed the tab minutes ago — this is the only thing that brings
 * them back to the ten rows that failed.
 */
class NotifyImportReady implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(QuestionImported $event): void
    {
        $import = $event->import;

        $uploader = User::query()->find($import->uploaded_by);

        if ($uploader === null) {
            return;
        }

        $failed = $import->status === ImportStatus::Failed;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $uploader,
            // Two types rather than one with a flag: the "done" wording is a
            // success sentence, and a file that was not a CSV at all would get it
            // reading «اكتمل الاستيراد… أُضيف 0».
            type: $failed ? NotificationType::QuestionImportFailed : NotificationType::QuestionImportReady,
            variables: $failed
                ? [
                    'name' => $uploader->name,
                    'filename' => $import->original_filename,
                    'reason' => (string) ($import->failure_reason ?? 'سببٌ غير معروف'),
                ]
                : [
                    'name' => $uploader->name,
                    'filename' => $import->original_filename,
                    'imported' => (string) $import->imported_count,
                    'skipped' => (string) $import->skipped_count,
                    'failed' => (string) $import->failed_count,
                ],
            actionUrl: '/manage/bank/import/'.$import->uuid,
            workspaceId: (int) $import->workspace_id,
        ));
    }
}
