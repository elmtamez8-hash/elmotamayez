<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SavePushSubscriptionRequest extends FormRequest
{
    /**
     * The push services a browser actually hands out endpoints on: Chrome and
     * every Chromium browser but Edge (FCM), Firefox (Mozilla autopush), Edge
     * (WNS) and Safari (APNs web push).
     *
     * ⚠️ THE SERVER POSTS TO WHATEVER IS STORED HERE. `WebPushChannel` sends an
     * encrypted body to the endpoint on every notification, so an address the
     * client chose freely is a request our own server makes to a host of the
     * client's choosing — an internal address, a metadata service, anybody's
     * API. «Starts with https://» said nothing about WHERE. A browser never
     * produces an endpoint off this list, so refusing one refuses nobody real.
     *
     * Exact hosts, and suffixes that carry their own leading dot so that
     * `evilpush.services.mozilla.com.attacker.test` and `xnotify.windows.com`
     * both fail.
     */
    private const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
    ];

    private const PUSH_HOST_SUFFIXES = [
        '.push.services.mozilla.com',
        '.notify.windows.com',
        '.push.apple.com',
    ];

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
            // push service — but its HOST is not, see PUSH_HOSTS.
            'endpoint' => ['required', 'string', 'starts_with:https://', 'max:2048', $this->knownPushService(...)],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'user_agent' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function knownPushService(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        $known = is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && (in_array($host, self::PUSH_HOSTS, true)
                || array_filter(self::PUSH_HOST_SUFFIXES, static fn (string $suffix): bool => str_ends_with($host, $suffix)) !== []);

        if (! $known) {
            $fail('عنوان الاشتراك لا يعود إلى خدمة إشعارات معروفة.');
        }
    }
}
