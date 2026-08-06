<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Exceptions;

use RuntimeException;

/**
 * Thrown when something asks a provider for a capability it never claimed.
 *
 * Loud on purpose. A provider that silently no-ops an unsupported host control
 * leaves a teacher pressing "mute" on a microphone that stays open — the failure
 * has to reach the person who pressed the button.
 */
class UnsupportedCapability extends RuntimeException
{
    public static function for(string $provider, string $capability): self
    {
        return new self("مزوّد البث «{$provider}» لا يدعم: {$capability}.");
    }
}
