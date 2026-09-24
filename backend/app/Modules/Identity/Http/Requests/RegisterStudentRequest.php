<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\ReferralCode;
use App\Modules\Marketplace\Actions\Public\ListSchoolYears;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\SchoolYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('referral_code');

        if (is_string($code)) {
            $normalised = ReferralCode::normalise($code);
            $this->merge(['referral_code' => $normalised === '' ? null : $normalised]);
        }
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
            /*
            | Spec 022 · FR-005 — the individual YEAR, which replaces the broad
            | stage as the question this form asks.
            |
            | ⚠️ `grade_level_slug` IS GONE FROM THIS FORM, not merely optional.
            | The student's stage is DERIVED from the year they picked
            | (`SchoolYear::stageFor`), and accepting both would store two answers
            | to one question that part company at the first edit of the mapping —
            | which FR-001ج forbids in words.
            |
            | ⚠️ AND THE PREDICATE IS `activelyOffered()`, THE SAME SCOPE THE
            | PUBLIC READ USES. Before this spec the rule here was `is_active`
            | alone while the screen was fed a participation-filtered list, so the
            | door accepted what the screen never showed — two answers to one
            | question, from opposite sides.
            */
            'school_year_slug' => ['required', 'string', Rule::in($this->offeredSchoolYearSlugs())],
            /*
            | Spec 011 · FR-042 — «حقل المنطقة يجب أن يكون إلزامياً في التسجيل».
            |
            | ⚠️ THE SLUG, NOT THE ROW ID, exactly as `grade_level_slug` beside it:
            | payloads in this product never carry an autoincrement id, and
            | `Rule::in` keeps the catalogue a closed set rather than whatever
            | happens to be in the table. The FK on `student_profiles` is
            | `region_id`; the resolution happens in the Action.
            |
            | ⚠️ AND THE CATALOGUE MUST NOT BE EMPTY. A required field validated
            | against zero rows refuses EVERY registration, which is why the
            | catalogue ships with a backfill migration and not with a seeder
            | alone.
            */
            'region_slug' => ['required', 'string', Rule::in($this->activeRegionSlugs())],
            'registered_by_parent' => ['boolean'],

            /*
            | Spec 011 · US3 — who invited them, if anybody.
            |
            | ⚠️ AN UNKNOWN CODE IS A 422 ON THIS FIELD, AND THAT REVERSES THE
            | ORIGINAL «attach nothing and say nothing». The form now carries the
            | field (and prefills it from a `?ref=` link), so a silent drop is the
            | hostile outcome: the friend is never credited and the newcomer
            | believes they were. The field is optional, so the person the
            | message stops can fix the code or clear it and carry on — nothing
            | about the account itself is refused.
            |
            | The «oracle» worry that kept `exists` out does not survive
            | measuring: a code is 8 characters over a 32-symbol alphabet
            | (~10¹² values) behind `throttle:registration`, and a hit says only
            | that SOME code exists, never whose. Normalised in
            | `prepareForValidation()` first — MySQL's collation would match a
            | lower-case code where SQLite does not, and the suite and production
            | must agree on the same input.
            */
            'referral_code' => ['nullable', 'string', 'max:12', Rule::exists('referral_codes', 'code')],
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
            'school_year_slug.in' => 'اختر الصف الدراسي من القائمة.',
            'region_slug.in' => 'اختر المنطقة من القائمة.',
            'referral_code.exists' => 'لم نجد هذا الكود. تأكّد منه، أو امسح الخانة وأكمل التسجيل بدونه.',
            'referral_code.max' => 'كود الإحالة أطول من اللازم. تأكّد منه، أو امسح الخانة.',
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
     * The regions a student may pick — active rows only.
     *
     * Platform reference data with no workspace column at all, so no scope is
     * involved and none has to be bypassed. Retired regions are excluded: a row
     * kept for the students already filed under it is not an option offered to
     * the next one.
     *
     * @return list<string>
     */
    private function activeRegionSlugs(): array
    {
        /** @var list<string> */
        return Region::query()
            ->where('is_active', true)
            ->pluck('slug')
            ->all();
    }

    /**
     * The school years actually on offer.
     *
     * Deliberate cross-workspace read (Constitution I): the slug is platform
     * vocabulary, not tenant data, and a student picking a year belongs to no
     * workspace yet. `exists:school_years,slug` would reach the same rows as a
     * raw query with no scope awareness at all — the trap the constitution names
     * — and, worse here, it could not express "and its stage is active" without
     * spelling the join a second time.
     *
     * ⚠️ `activelyOffered()` IS THE ONE PREDICATE, shared with
     * {@see ListSchoolYears}. Two
     * spellings of "which years are on offer" put one answer on the screen and
     * another at the door.
     *
     * @return list<string>
     */
    private function offeredSchoolYearSlugs(): array
    {
        /** @var list<string> */
        return SchoolYear::query()
            ->activelyOffered()
            ->pluck('slug')
            ->all();
    }
}
