<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * The host controls — the three the spec names (FR-016) and the bulk forms.
 *
 * ⚠️ THE BULK FORMS ARE NOT LOOPS OVER THE SINGLE ONES AT THE CALLER. A teacher
 * with twenty students pressing «كتم» twenty times is twenty requests, twenty
 * authorisation checks and twenty chances for one to fail in the middle — and the
 * room is left half muted with nothing saying which half. One request, one
 * verdict, and the provider walks its own participant list.
 */
enum HostAction: string
{
    case Mute = 'mute';
    case Remove = 'remove';
    case End = 'end';
    case MuteAll = 'mute-all';
    case RemoveAll = 'remove-all';
    case LowerHands = 'lower-hands';
    case Readmit = 'readmit';

    public function label(): string
    {
        return match ($this) {
            self::Mute => 'كتم',
            self::Remove => 'إخراج',
            self::End => 'إنهاء',
            self::MuteAll => 'كتم الجميع',
            self::RemoveAll => 'إخراج الجميع',
            self::LowerHands => 'إنزال الأيدي',
            self::Readmit => 'السماح بالعودة',
        };
    }

    /** Whether this action names someone in particular. */
    public function requiresTarget(): bool
    {
        return $this === self::Mute || $this === self::Remove || $this === self::Readmit;
    }

    /**
     * Whether it acts on the room rather than on a person.
     *
     * ⚠️ AND THE HOST IS ALWAYS EXCLUDED. «الجميع» means everyone the teacher is
     * teaching, never the teacher: muting yourself with a room control is a
     * puzzle, and removing yourself ends the lesson for the class by the back
     * door — the room stays open, the recording runs, and the one person who can
     * close it is outside.
     */
    public function isBulk(): bool
    {
        return $this === self::MuteAll || $this === self::RemoveAll || $this === self::LowerHands;
    }
}
