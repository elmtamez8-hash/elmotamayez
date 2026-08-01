<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Marketplace\Models\GradeLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterStudentRequest extends FormRequest
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
            // E.164, which is what "phone with a country code" means once you stop
            // hand-parsing separators (FR-063).
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'grade_level_slug' => ['required', 'string', Rule::in($this->publicGradeLevelSlugs())],
            'registered_by_parent' => ['boolean'],
            // FR-065: ships unchecked, so an absent value must fail rather than
            // quietly default to consent.
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
            'country.size' => 'اختر الدولة.',
            'grade_level_slug.in' => 'اختر مرحلة دراسية من القائمة.',
            'terms_accepted.accepted' => 'يجب الموافقة على الشروط والأحكام.',
        ];
    }

    /**
     * Grade-level slugs offered anywhere on the platform.
     *
     * Deliberate cross-workspace read (Constitution I): the slug is platform
     * vocabulary, not tenant data, and a student picking a grade belongs to no
     * workspace yet. `exists:grade_levels,slug` would reach the same rows but as
     * a raw query with no scope awareness at all — the trap the constitution
     * names. Rule::in also keeps the taxonomy a closed set rather than whatever
     * happens to be in the table.
     *
     * @return list<string>
     */
    private function publicGradeLevelSlugs(): array
    {
        /** @var list<string> */
        return GradeLevel::query()
            ->withoutWorkspaceScope()
            ->where('is_active', true)
            ->distinct()
            ->pluck('slug')
            ->all();
    }
}
