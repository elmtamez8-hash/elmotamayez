<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Actions\UpdateTeacherProfile;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\TeacherListingRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ما يُعدِّلُه المدرّسُ في ملفِّه بعدَ اعتمادِه.
 *
 * ⚠️ الحقولُ هي حقولُ الخطوةِ الثانيةِ بعينِها، من {@see TeacherListingRules}.
 * فما كتبَه المعالجُ يُعدَّلُ هنا، وما كتبَه قرارٌ لا يُعدَّل: `approval_status`
 * و`is_publicly_listed` و`is_verified` قراراتٌ لا بيانات، و`hourly_rate` مسارُ
 * تغييرِه طلبُ تعديلِ سعرٍ في ٠١٤. القائمةُ البيضاءُ التي تحرسُ ذلكَ تعيشُ في
 * {@see UpdateTeacherProfile} ولا تُكرَّرُ هنا.
 *
 * ⚠️ و`slug` ليسَ منها رغمَ كونِه في قائمةِ الإجراءِ البيضاء: عنوانُ الصفحةِ
 * العامّةِ له بابُه `PUT /teacher/profile/slug` بإجراءٍ يعرفُ التفرّدَ وإعادةَ
 * التوجيه، ولو مرَّ من هنا لتغيَّرَ عنوانُ مدرّسٍ في نفسِ الطلبِ الذي غيَّرَ وصفَه.
 */
class UpdateTeacherProfileRequest extends FormRequest
{
    /**
     * The refusal is the CONTROLLER's, so it carries a sentence.
     *
     * `authorize()` returning false answers with the framework's own English
     * default; the neighbouring `updateSlug()` aborts with «لا يوجد ملف مدرّس
     * لهذا الحساب» and this door says the same thing for the same reason.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return TeacherListingRules::fields();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TeacherListingRules::messages();
    }

    /**
     * The caller's own listing — never a route parameter.
     *
     * With nothing to name there is no ownership check to forget, the same
     * reason `updateSlug()` takes none.
     */
    public function profile(): ?TeacherProfile
    {
        return $this->user()?->teacherProfile;
    }
}
