<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Support\PendingTransfer;
use App\Shared\Actions\Action;
use DomainException;

/**
 * The student takes their own request back.
 *
 * ⚠️ IT IS A STATUS, NOT A DELETE. The request happened, and the history says
 * so: a row removed from the table leaves the teacher's queue shorter with
 * nothing to explain where it went, and a student who withdrew and re-submitted
 * three times reads as one who asked once.
 *
 * `dropped` rather than `rejected` — nobody refused them anything.
 */
class WithdrawTransferRequest extends Action
{
    public function handle(CohortTransferRequest $request, User $student): void
    {
        if ((int) $request->student_user_id !== (int) $student->getKey()) {
            throw new DomainException('هذا الطلب ليس لك.');
        }

        if (! $request->isPending()) {
            throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
        }

        PendingTransfer::drop(
            (int) $request->course_id,
            (int) $student->getKey(),
            $student,
            'سحب الطالب طلبه.',
        );
    }
}
