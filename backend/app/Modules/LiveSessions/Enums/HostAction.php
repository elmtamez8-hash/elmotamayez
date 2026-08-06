<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/** The three host controls the spec names (FR-016). */
enum HostAction: string
{
    case Mute = 'mute';
    case Remove = 'remove';
    case End = 'end';

    public function label(): string
    {
        return match ($this) {
            self::Mute => 'كتم',
            self::Remove => 'إخراج',
            self::End => 'إنهاء',
        };
    }

    /** Whether this action names someone in particular. */
    public function requiresTarget(): bool
    {
        return $this !== self::End;
    }
}
