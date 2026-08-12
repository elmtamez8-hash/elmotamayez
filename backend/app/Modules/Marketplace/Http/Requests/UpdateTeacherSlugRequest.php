<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The teacher choosing the segment their public profile lives at.
 *
 * Every rule here defends a URL, not a string.
 */
class UpdateTeacherSlugRequest extends FormRequest
{
    /**
     * ⚠️ A slug shaped like a uuid is a PROFILE HIJACK, not a formatting nit.
     *
     * `ShowPublicTeacher` resolves `slug OR uuid` so that links shared before the
     * slug existed still work. Set your slug to another teacher's uuid and the
     * lookup matches two rows — and returns whichever the database hands back
     * first. Their URL now sometimes shows your profile.
     */
    private const UUID_SHAPED = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function authorize(): bool
    {
        // The profile is resolved from the authenticated user in the controller,
        // never from a route parameter, so there is no other profile to reach.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                // Three at the short end: two characters is a namespace worth
                // squatting. Sixty at the long end because a name plus a city is
                // a reasonable thing to want.
                'min:3',
                'max:60',
                // Lowercase, digits, single hyphens between them. Uppercase is
                // excluded rather than folded: two URLs differing only in case
                // are two URLs to a crawler and one to a person.
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                'not_regex:'.self::UUID_SHAPED,
                // ⚠️ Rule::unique WITHOUT the workspace scope — `Rule::unique` is
                // a raw query, which is what we want here for once: the URL is one
                // platform-wide namespace, so a scoped check would pass a
                // duplicate and let the unique index reject it at write time.
                Rule::unique('teacher_profiles', 'slug')
                    ->ignore($this->user()?->teacherProfile?->getKey()),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'الرابط يقبل الحروف الإنجليزية الصغيرة والأرقام والشرطة (-) فقط.',
            'slug.not_regex' => 'هذا الشكل محجوز للمعرّفات الداخلية، اختر رابطاً آخر.',
            'slug.unique' => 'هذا الرابط مستخدم بالفعل، جرّب رابطاً آخر.',
        ];
    }

    /**
     * Trim and lowercase before validating, not after.
     *
     * A teacher pasting `Ahmed-Almansouri ` should be told it is available, not
     * told it has a capital letter in it. Normalising after validation would
     * mean the value that was checked for uniqueness is not the value stored.
     */
    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        if (is_string($slug)) {
            $this->merge(['slug' => mb_strtolower(trim($slug))]);
        }
    }

    public function slug(): string
    {
        /** @var string */
        return $this->validated('slug');
    }

    public function profile(): ?TeacherProfile
    {
        return $this->user()?->teacherProfile;
    }
}
