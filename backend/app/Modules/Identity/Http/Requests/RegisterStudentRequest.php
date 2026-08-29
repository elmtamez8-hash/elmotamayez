<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Marketplace\Models\GradeLevel;
use Carbon\CarbonImmutable;
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

            /*
            | Spec 011 · US3 — who invited them, if anybody.
            |
            | ⚠️ SHAPE ONLY, AND NO `exists:` RULE. An unknown code must not fail
            | a registration; `AttachReferral` attaches nothing and says nothing.
            | An `exists:` rule would also be an oracle — a 422 for an unknown
            | code and a 201 for a real one enumerates who is on the platform.
            */
            'referral_code' => ['nullable', 'string', 'max:12'],
            /*
            | Spec 013 — the age question, asked once at the door.
            |
            | ⚠️ REQUIRED HERE AND NULLABLE IN THE COLUMN, and the asymmetry is
            | deliberate: no source can fill the date for the accounts that already
            | exist, but every account created from now on can be asked. `FR-009ج`
            | keeps NULL meaningful for the old rows.
            |
            | `before:today` rather than a minimum age: refusing an under-age
            | registration outright is not what the spec asks for — it asks for a
            | guardian's consent, which is a different and gentler answer.
            */
            'date_of_birth' => ['required', 'date', 'before:today'],
            /*
            | ⚠️ REQUIRED ONLY FOR A MINOR, and that is enforceable here because the
            | date is in the same payload. An adult signing up for themselves has no
            | guardian to name, and demanding one would be a wall in front of every
            | grown student on the platform.
            */
            'guardian_contact' => [
                Rule::requiredIf(fn (): bool => $this->isMinor()),
                'nullable',
                'string',
                'regex:/^\+[1-9]\d{6,14}$/',
            ],
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
            'date_of_birth.before' => 'أدخل تاريخ ميلادٍ صحيحاً.',
            'guardian_contact.required' => 'لأنّك دون الثامنة عشرة، أدخل رقم جوّال وليّ أمرك ليوافق على تفعيل حسابك.',
            'guardian_contact.regex' => 'أدخل رقم جوّال وليّ الأمر مع رمز الدولة، مثل ‎+97455512345.',
        ];
    }

    /**
     * Whether the date in THIS payload puts the applicant under eighteen.
     *
     * ⚠️ READ FROM THE REQUEST, NOT FROM A STORED ROW — there is no stored row
     * yet. And a malformed date answers `false` rather than throwing: the `date`
     * rule above reports that problem in its own field, and a validator that dies
     * while deciding whether another rule applies renders no message at all.
     */
    private function isMinor(): bool
    {
        $raw = $this->input('date_of_birth');

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($raw)->diffInYears(CarbonImmutable::now()) < 18;
        } catch (\Throwable) {
            return false;
        }
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
            ->where('is_active', true)
            ->distinct()
            ->pluck('slug')
            ->all();
    }
}
