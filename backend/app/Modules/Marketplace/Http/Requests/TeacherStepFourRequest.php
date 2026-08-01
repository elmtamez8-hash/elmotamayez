<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeacherStepFourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:99999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'availability' => ['required', 'array', 'min:1'],
            'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'availability.*.start_time' => ['required', 'date_format:H:i,H:i:s'],
            'availability.*.end_time' => ['required', 'date_format:H:i,H:i:s'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'هذا الحقل مطلوب.',
            'availability.min' => 'أضف فترة توفّر واحدة على الأقل.',
            'availability.*.start_time.date_format' => 'صيغة الوقت غير صحيحة.',
            'availability.*.end_time.date_format' => 'صيغة الوقت غير صحيحة.',
        ];
    }
}
