<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Data\TeacherStepOneData;
use Illuminate\Foundation\Http\FormRequest;

class RegisterTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'terms_accepted' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'هذا الحقل مطلوب.',
            'email.email' => 'أدخل بريداً إلكترونياً صحيحاً.',
            'email.unique' => 'هذا البريد الإلكتروني مسجّل بالفعل.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 8 أحرف.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
            'phone.regex' => 'أدخل رقم الجوال مع رمز الدولة، مثل ‎+97455512345.',
            'terms_accepted.accepted' => 'يجب الموافقة على الشروط والأحكام.',
        ];
    }

    public function toDto(): TeacherStepOneData
    {
        return TeacherStepOneData::fromArray($this->validated());
    }
}
