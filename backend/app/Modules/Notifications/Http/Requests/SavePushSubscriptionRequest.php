<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePushSubscriptionRequest extends FormRequest
{
    /**
     * Being signed in is the whole authorisation, and asking for more would be a
     * bug rather than caution.
     *
     * ⚠️ NO `can()` HERE. spatie runs in TEAM MODE, and a real student is a member
     * of no workspace — nothing on their path writes `users.last_workspace_id` —
     * so the team id is null, they hold no role, and every permission check for
     * them is false. That exact line has now refused every student on the platform
     * three times: `EnrollmentPolicy::view()` (2026-08-26), `BuildSelfExamRequest`
     * (2026-08-27) and `SubmitAttemptRequest` (2026-08-30). Registering one's own
     * phone needs no permission; the row is written against
     * `$this->currentUser($request)` and can belong to nobody else.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Not `url`: an endpoint is opaque to us and its shape belongs to the
            // push service, but it must at least be an https address we can post
            // to — a `http://` one would be refused by the protocol anyway.
            'endpoint' => ['required', 'string', 'starts_with:https://', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'user_agent' => ['nullable', 'string', 'max:255'],
        ];
    }
}
