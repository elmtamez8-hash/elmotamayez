<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Actions\TerminateAuthSession;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Http\Request;

/**
 * Which machine signed in, and what that does to the machines already signed in.
 *
 * ⚠️ EXTRACTED THE DAY A SECOND DOOR NEEDED IT, NOT BEFORE. `StartAuthSession`
 * owned all of this while the API was the only way in; spec 037 · story 2 adds
 * the panel door, and a device limit written twice is two limits that agree until
 * the first one moves. This repository has paid for that shape often enough to
 * name it: one question, one spelling.
 *
 * The two rules the algorithm encodes are the same ones as before and are just as
 * easy to get wrong:
 *
 * The new session is created FIRST, then older ones are evicted — a fresh sign-in
 * is never refused, because the account holder is as likely to be the new device
 * as the old one.
 *
 * The unit counted is the DEVICE, not the session. Signing in twice on one laptop
 * — an expired token, the panel and the frontend, a second browser profile — is
 * several sessions on one machine. Counting sessions at a limit of one would log
 * a student out of the computer they are sitting at while leaving the person they
 * shared the password with untouched.
 *
 * ⚠️ That second rule is what makes the owner's 2026-09-17 decision hold with no
 * code of its own: the panel and the frontend on one machine share a fingerprint,
 * so they resolve to ONE `devices` row and count once.
 */
class DeviceRegistry
{
    public function __construct(
        private readonly TerminateAuthSession $terminate,
        private readonly DispatchNotification $notify,
    ) {}

    public function resolve(User $user, Request $request): Device
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

    public function enforceLimit(User $user, Device $current, AuthSession $newSession): void
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
     *
     * ⚠️ Only `student` is configured today, so for a panel sign-in this returns
     * null and evicts nothing. It is still asked rather than skipped: the map is a
     * `platform_settings` row an operator edits, so a teacher limit added tomorrow
     * must bite at every door — and a door that never consults the limit is a hole
     * nobody would notice opening.
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
