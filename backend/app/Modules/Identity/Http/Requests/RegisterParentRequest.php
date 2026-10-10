<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterParentRequest extends FormRequest
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
            // No `confirmed` (owner decision 2026-10-10, as the student form): the
            // form shows the password behind an eye toggle instead of asking twice.
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            // Ships unchecked, so a missing value must fail rather than default to
            // consent (FR-065).
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
            'phone.regex' => 'أدخل رقم الجوال مع رمز الدولة، مثل ‎+97455512345.',
            'country.size' => 'اختر الدولة.',
            'terms_accepted.accepted' => 'يجب الموافقة على الشروط والأحكام.',
        ];
    }
}
