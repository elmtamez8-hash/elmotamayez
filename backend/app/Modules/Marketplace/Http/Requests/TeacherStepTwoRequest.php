<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Support\TeacherListingRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ⚠️ القاعدةُ في {@see TeacherListingRules} لا هنا، لأنّ لها بابَينِ لا باباً:
 * هذا المعالجُ، و`PUT /teacher/profile` بعدَ الاعتماد. ونسختانِ تفترقانِ عندَ
 * أوّلِ تعديلٍ ثمّ يقبلُ أحدُهما ما يرفضُه الآخرُ بلا أن يفشلَ شيء.
 */
class TeacherStepTwoRequest extends FormRequest
{
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
}
