<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnrollmentResource\Pages;

use App\Filament\Resources\EnrollmentResource;
use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ChangeEnrollmentStatus;
use DomainException;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditEnrollment extends EditRecord
{
    protected static string $resource = EnrollmentResource::class;

    /**
     * ⚠️ الحالةُ تمرُّ بـ{@see ChangeEnrollmentStatus} وحدَه، وفيه الرفضُ كلُّه:
     * «ملغى» لا يُختارُ من هنا، وصفٌّ مُلغى لا تُقبَلُ له حالة، وتسجيلُ اشتراكٍ
     * انتهى وصولُه لا يُعادُ فتحُه، و«منتهٍ» على تسجيلٍ يمنحُ الوصولَ يُحرِّرُ
     * مقاعدَه. كانت هذه القواعدُ مكتوبةً في الصفحةِ وحدَها — والقائمةُ المُرشَّحةُ
     * تُشكِّلُ طلباً واحداً لا الذي يليه، فالحارسُ في الفعل.
     *
     * رفضُ الفعلِ يصلُ المشغِّلَ خطأً على الحقل، لا صفحةَ خطأ.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Enrollment $record */
        $actor = Auth::user();
        $to = is_string($data['status'] ?? null) ? EnrollmentStatus::tryFrom($data['status']) : null;

        if (! $actor instanceof User || $to === null) {
            return $record;
        }

        try {
            return app(ChangeEnrollmentStatus::class)->handle($actor, $record, $to);
        } catch (DomainException $refusal) {
            throw ValidationException::withMessages(['data.status' => $refusal->getMessage()]);
        }
    }
}
