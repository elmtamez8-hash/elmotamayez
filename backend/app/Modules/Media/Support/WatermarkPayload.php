<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Models\User;

/**
 * The identifying overlay, built on the server.
 *
 * Masked here rather than in the browser, and that is the whole point: masking
 * client-side would mean the full phone number travelled to the client, where it
 * is readable from the network tab in ten seconds. A protection feature that
 * ships the thing it protects is a leak with extra steps.
 */
final class WatermarkPayload
{
    /** @return array{name: string, phone_masked: string|null} */
    public static function for(User $user): array
    {
        return [
            'name' => $user->name,
            'phone_masked' => self::mask($user->phone),
        ];
    }

    /**
     * Last four digits only (FR-019).
     *
     * Enough for the teacher to identify a leaker from a photographed screen,
     * not enough for someone sitting next to the viewer to read their number.
     */
    private static function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 4) {
            return null;
        }

        return '…'.substr($digits, -4);
    }
}
