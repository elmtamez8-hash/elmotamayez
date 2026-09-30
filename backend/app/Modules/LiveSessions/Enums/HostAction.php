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
    // 2026-09-30 — the microphone and the shared screen as PERMISSIONS, not
    // as a mute a student undoes with one tap. See `RoomMediaRights`.
    case AllowMic = 'allow-mic';
    case AllowAllMics = 'allow-all-mics';
    case AllowScreenShare = 'allow-screen-share';
    case RevokeScreenShare = 'revoke-screen-share';

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
            self::AllowMic => 'السماح بالكلام',
            self::AllowAllMics => 'السماح للجميع بالكلام',
            self::AllowScreenShare => 'السماح بمشاركة الشاشة',
            self::RevokeScreenShare => 'منع مشاركة الشاشة',
        };
    }

    /** Whether this action names someone in particular. */
    public function requiresTarget(): bool
    {
        return match ($this) {
            self::Mute, self::Remove, self::Readmit,
            self::AllowMic, self::AllowScreenShare, self::RevokeScreenShare => true,
            self::End, self::MuteAll, self::RemoveAll, self::LowerHands, self::AllowAllMics => false,
        };
    }

    /**
     * Whether it changes what a STUDENT may publish — decided by us, stored on
     * the seat or the session, and only then pushed to the provider.
     *
     * ⚠️ NONE OF THESE CAN END A LESSON OR DISCONNECT ANYONE. They narrow or
     * widen the microphone and the screen and nothing else; ending is `End`
     * alone, and removal is `Remove`/`RemoveAll` alone. The match below has no
     * `default`, so a new case must be classified here on purpose.
     */
    public function changesPublishRights(): bool
    {
        return match ($this) {
            self::Mute, self::MuteAll, self::AllowMic, self::AllowAllMics,
            self::AllowScreenShare, self::RevokeScreenShare => true,
            self::Remove, self::End, self::RemoveAll, self::LowerHands, self::Readmit => false,
        };
    }

    /**
     * Whether it acts on the room rather than on a person.
     *
     * ⚠️ AND EVERY HOST IS ALWAYS EXCLUDED — not only the one who pressed it
     * (2026-09-30). «الجميع» means everyone being TAUGHT: the caller hands the
     * provider the seat holders' identities and the provider acts on nobody
     * else, so a co-teacher, an assistant host and the recorder are untouched.
     * It used to skip the actor alone, which muted and removed a second host.
     * Muting yourself with a room control is a puzzle, and removing yourself ends the lesson for the class by the back
     * door — the room stays open, the recording runs, and the one person who can
     * close it is outside.
     */
    public function isBulk(): bool
    {
        return match ($this) {
            self::MuteAll, self::RemoveAll, self::LowerHands, self::AllowAllMics => true,
            self::Mute, self::Remove, self::End, self::Readmit,
            self::AllowMic, self::AllowScreenShare, self::RevokeScreenShare => false,
        };
    }
}
