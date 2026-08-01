<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\TeacherApplication;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Persist one wizard step so the applicant can leave and come back (FR-070).
 *
 * The editability rule lives here rather than in a FormRequest because Filament
 * and the seeders reach the application through this Action too (Constitution II)
 * — a submitted application must not change under the reviewer.
 */
class SaveTeacherApplicationStep extends Action
{
    /** @param array<string, mixed> $answers */
    public function handle(TeacherApplication $application, int $step, array $answers): TeacherApplication
    {
        if (! $application->isEditable()) {
            throw new DomainException('لا يمكن تعديل الطلب بعد إرساله.');
        }

        if ($step < 2 || $step > TeacherApplication::LAST_STEP) {
            throw new DomainException('رقم الخطوة غير صحيح.');
        }

        $application->putStep($step, $answers);
        $application->save();

        return $application;
    }
}
