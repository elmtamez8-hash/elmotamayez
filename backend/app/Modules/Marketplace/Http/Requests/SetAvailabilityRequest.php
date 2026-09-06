<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\AvailabilityRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * مواعيدُ المدرّسِ الأسبوعيّةُ بعدَ اعتمادِه.
 *
 * ⚠️ الحقولُ حقولُ الخطوةِ الرابعةِ بعينِها، من {@see AvailabilityRules} — عدا
 * `hourly_rate`: سعرُ المدرّسِ لا يُعدَّلُ من هنا ولا من `‎/admin`، ومسارُه طلبُ
 * تعديلِ سعرٍ في ٠١٤ (`POST /settlement/rate-requests`) لأنّ السعرَ يُعتمَدُ ولا
 * يُعلَن.
 *
 * ولا وسيطَ في المسار: الملفُّ يُشتَقُّ من حاملِ الرمز، فلا فحصَ ملكيّةٍ يُنسى —
 * الهجاءُ نفسُه في `‎PUT /teacher/profile` جارِه.
 */
class SetAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return AvailabilityRules::fields();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return AvailabilityRules::messages();
    }

    public function profile(): ?TeacherProfile
    {
        return $this->user()?->teacherProfile;
    }
}
