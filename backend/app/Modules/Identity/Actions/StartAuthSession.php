<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Support\DeviceFingerprint;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Actions\Action;
use Illuminate\Http\Request;

/**
 * Signs a user in on a device, and enforces one device at a time.
 *
 * Two things about the algorithm are deliberate and easy to get wrong.
 *
 * The new session is created FIRST, then older ones are evicted. The
 * requirement is explicit that a fresh sign-in is never refused — the account
 * holder is as likely to be the new device as the old one, and refusing would
 * strand whoever is actually holding the phone.
 *
 * The unit counted is the DEVICE, not the session. Signing in twice on one
 * laptop — an expired token, a second browser profile — is two sessions on one
 * machine. Counting sessions with a limit of one would log a student out of the
 * computer they are sitting at, punishing the account holder while leaving the
 * person they shared the password with untouched.
 */
class StartAuthSession extends Action
{
    public function __construct(
        private readonly TerminateAuthSession $terminate,
        private readonly DispatchNotification $notify,
    ) {}

    public function handle(User $user, Request $request, string $tokenName = 'auth-token'): AuthSessionResult
    {
        $device = $this->resolveDevice($user, $request);

        $token = $user->createToken($tokenName);

        $session = AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
            'token_id' => $token->accessToken->getKey(),
            'status' => AuthSession::STATUS_ACTIVE,
            'ip_hash' => $request->ip() === null ? null : hash('sha256', $request->ip()),
            'last_active_at' => now(),
        ]);

        $this->enforceDeviceLimit($user, $device, $session);

        return new AuthSessionResult($session, $token->plainTextToken);
    }

    private function resolveDevice(User $user, Request $request): Device
    {
        $device = Device::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'fingerprint_hash' => DeviceFingerprint::hash($request),
        ]);

        $device->label = DeviceFingerprint::label($request);
        $device->last_seen_at = now();
        $device->save();

        return $device;
    }

    private function enforceDeviceLimit(User $user, Device $current, AuthSession $newSession): void
    {
        $limit = $this->limitFor($user);

        if ($limit === null) {
            return;
        }

        $devices = AuthSession::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->selectRaw('device_id, MIN(created_at) as oldest')
            ->groupBy('device_id')
            ->orderBy('oldest')
            ->get();

        $excess = $devices->count() - $limit;

        foreach ($devices as $row) {
            if ($excess <= 0) {
                break;
            }

            $deviceId = (int) $row->device_id;

            // Never evict the device that just signed in, even if the clock
            // makes it look oldest.
            if ($deviceId === (int) $current->getKey()) {
                continue;
            }

            $this->evictDevice($user, $deviceId, $newSession);
            $excess--;
        }
    }

    private function evictDevice(User $user, int $deviceId, AuthSession $newSession): void
    {
        $sessions = AuthSession::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where('device_id', $deviceId)
            ->get();

        $wasRecentlyActive = false;
        $window = (int) config('media.device_alert_active_within_minutes', 30);

        foreach ($sessions as $session) {
            if ($session->last_active_at !== null && $session->last_active_at->gt(now()->subMinutes($window))) {
                $wasRecentlyActive = true;
            }

            $this->terminate->handle($session, SessionEndReason::DeviceLimit);
        }

        // Only alert when something live was actually interrupted. At a limit of
        // one device this fires on every ordinary move between phone and laptop,
        // and a notification that arrives daily is one the user learns to
        // dismiss — which is exactly when it stops working as a warning.
        if ($wasRecentlyActive) {
            $this->notify->handle(new NotificationRequest(
                recipient: $user,
                type: NotificationType::SecurityAlert,
                variables: [
                    'name' => $user->name,
                    'event' => 'سُجّل الدخول إلى حسابك من جهاز جديد ('
                        .$newSession->device->label
                        .')، وأُنهيت جلستك السابقة.',
                ],
            ));
        }
    }

    /**
     * Null means no limit. A teacher with the panel, a laptop and a phone open is
     * doing legitimate work; a limit there obstructs them rather than protecting
     * anything.
     */
    private function limitFor(User $user): ?int
    {
        /** @var array<string, int> $limits */
        $limits = PlatformSettings::get('auth.device_limits', []);

        $role = $user->platform_role?->value;

        if ($role === null || ! array_key_exists($role, $limits)) {
            return null;
        }

        return max(1, (int) $limits[$role]);
    }
}
