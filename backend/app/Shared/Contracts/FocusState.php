<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * Whether someone is in the middle of a focus session (spec 009 · FR-039).
 *
 * Exists so Notifications can mute optional messages without reaching into
 * Gamification's models — the same shape as {@see EnrollmentDirectory} and the
 * five other contracts in this directory, which is Constitution III's mechanism
 * rather than an abstraction for its own sake.
 *
 * ⚠️ THE ANSWER COMES FROM THE `focus_sessions` TABLE AND FROM NOWHERE ELSE. A
 * cache key beside it was the first design and would have been a second copy of
 * one fact: every hazard it needed warning about — a missed `close()` leaving a
 * student muted for ever — exists ONLY because there would have been two copies.
 */
interface FocusState
{
    public function isFocusing(User $user): bool;
}
