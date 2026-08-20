<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Models\User;

/**
 * The abbreviated name a student appears under on a surface that is not theirs.
 *
 * "خالد ك." — enough to show a real person, not enough to identify a minor to
 * strangers on a platform-wide board (FR-027 · Q6).
 *
 * ⚠️ EXTRACTED FROM `Review::studentDisplayName()`, WHICH REMAINS AND NOW CALLS
 * THIS. Q6's promise was "zero new column, zero migration, one behaviour on every
 * public surface", and the original could not deliver it from here: it is an
 * INSTANCE method on a Marketplace model that reads `$this->student`, so
 * Gamification would have had to construct a Marketplace model — reaching across
 * a module boundary the constitution forbids — or copy the logic and let the two
 * drift at the first fix. One function, two callers.
 */
final class DisplayName
{
    public static function forStudent(?User $student): string
    {
        if ($student === null) {
            return 'طالب';
        }

        $surname = (string) $student->last_name;

        /*
        | Skip the definite article first.
        |
        | A large share of Arab family names begin with "ال", so taking character
        | zero abbreviates الكواري, العطية and الهاجري all to "ا." — an initial
        | that distinguishes nobody, on a board whose whole purpose is telling
        | classmates apart.
        */
        if (mb_strlen($surname) > 2 && mb_substr($surname, 0, 2) === 'ال') {
            $surname = mb_substr($surname, 2);
        }

        $initial = mb_substr($surname, 0, 1);

        return $initial === '' ? $student->first_name : $student->first_name.' '.$initial.'.';
    }
}
