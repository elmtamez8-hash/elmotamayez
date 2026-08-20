<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use App\Models\User;
use App\Modules\Compliance\Models\DataCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsentCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The real gate is the guardian check in the controller, which needs the
        // resolved subject — and a `false` here would answer 403 for a malformed
        // payload too, which is a different fact.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | ⚠️ THE UUID OF THE STUDENT, absent when the caller is acting for
            | themselves. A guardian of three needs to say which child; nobody
            | needs to name themselves.
            */
            'student_uuid' => ['nullable', 'string', 'uuid'],

            /*
            | ⚠️ `present`, NOT `required`. An empty array is a MEANINGFUL answer —
            | "I consent to none of the optional categories" — and `required`
            | rejects `[]` in Laravel, which would make total withdrawal the one
            | choice the endpoint cannot express.
            */
            'categories' => ['present', 'array'],
            'categories.*' => ['string', Rule::in($this->knownCategoryKeys())],

            // The version the client actually rendered. Compared in the
            // controller against the one in force.
            'version' => ['required', 'string', 'max:32'],
        ];
    }

    /** @return list<string> */
    public function categories(): array
    {
        /** @var list<string> $categories */
        $categories = array_values(array_unique((array) $this->input('categories', [])));

        return $categories;
    }

    /**
     * The student this is about.
     *
     * ⚠️ RESOLVED HERE AND CHECKED IN THE CONTROLLER, never bound implicitly on
     * the route. `WorkspaceScope` is inert for a student — they belong to no
     * workspace — so an implicit binding would resolve ANY account on the platform
     * before a single check ran, and the response would come back carrying their
     * name.
     */
    public function subject(User $caller): ?User
    {
        $uuid = $this->input('student_uuid');

        if (! is_string($uuid) || $uuid === '') {
            return $caller;
        }

        return User::query()->where('uuid', $uuid)->first();
    }

    /** @return list<string> */
    private function knownCategoryKeys(): array
    {
        /** @var list<string> */
        return DataCategory::query()->pluck('key')->all();
    }
}
