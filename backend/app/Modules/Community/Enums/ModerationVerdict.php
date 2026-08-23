<?php

declare(strict_types=1);

namespace App\Modules\Community\Enums;

/**
 * What a moderator decided.
 *
 * ⚠️ LIFTING A BAN IS A VERDICT, NOT A DELETED ROW. `moderation_actions` IS the
 * record `FR-021` requires — who acted and why — so removing the ban row would
 * erase the fact that somebody was banned and the reason given. "Is this person
 * banned right now" is answered by the most recent row winning, never by a row's
 * existence.
 */
enum ModerationVerdict: string
{
    /** A message taken out of the room; the row stays for the audit (FR-015). */
    case Hidden = 'hidden';

    /** A participant stopped from writing anywhere in the workspace (FR-022). */
    case Banned = 'banned';

    /** That ban ended. A new row, never a deletion. */
    case Unbanned = 'unbanned';

    /** Somebody reported content the term list did not catch (FR-024). */
    case Reported = 'reported';

    /** A report looked at and left alone. Closing the loop is part of the record. */
    case Dismissed = 'dismissed';
}
