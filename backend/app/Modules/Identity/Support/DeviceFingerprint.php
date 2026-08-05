<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Http\Request;

/**
 * Identifies the machine a request came from — approximately.
 *
 * Every signal here originates on the client, so this is forgeable and saying
 * otherwise would be a lie. It is not where the limit is enforced: the limit is
 * a count of live sessions in our own database, which no client can touch.
 * Forging a fingerprint only merges you into an existing device slot, which
 * still leaves the account on one device at a time.
 *
 * What it does buy is that a returning browser is recognised as the same device
 * instead of accumulating a new one on every sign-in.
 */
final class DeviceFingerprint
{
    public static function hash(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) $request->header('X-Device-Id', ''),
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language', ''),
        ]));
    }

    /**
     * Something the account holder can recognise in their device list.
     *
     * Coarse on purpose: "Chrome على ويندوز" is what a person checking for an
     * intruder needs, and a full user-agent string is noise they cannot read.
     */
    public static function label(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'متصفّح',
        };

        $platform = match (true) {
            str_contains($agent, 'Android') => 'أندرويد',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'ويندوز',
            str_contains($agent, 'Mac OS') => 'ماك',
            str_contains($agent, 'Linux') => 'لينكس',
            default => 'جهاز غير معروف',
        };

        return "{$browser} على {$platform}";
    }
}
