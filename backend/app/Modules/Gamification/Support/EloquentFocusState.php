<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

use App\Models\User;
use App\Modules\Gamification\Enums\FocusSessionStatus;
use App\Modules\Gamification\Models\FocusSession;
use App\Shared\Contracts\FocusState;

/**
 * Gamification owns the focus session; Notifications asks through the interface.
 *
 * One indexed lookup on `(user_id, status)`. It runs on every notification
 * dispatch, which is why the index exists — and why there is no cache key beside
 * it: a second copy of this fact is what would make a missed close permanent.
 *
 * ⚠️ THE CLOCK IS PART OF THE QUESTION, AND ITS ABSENCE MUTED A BELL FOR EVER.
 * This asked `status = running` and nothing else — but a person closing the tab
 * on a timer never closes the row, so a forty-five-minute session started on
 * 2026-08-21 was still «running» on the 24th, and `muteDuringFocus()` filters
 * both the unread COUNT and the feed. The owner's notifications had simply
 * stopped: no badge, no error, nothing anywhere naming a cause, and no later
 * message could ever restore it. `FR-039` says the mute lasts «exactly the length
 * of the session», and that sentence is only true if somebody reads the length.
 *
 * ⚠️ AND THE COMPARISON IS IN PHP, NOT IN SQL. The window is `started_at` plus a
 * PER-ROW `planned_minutes`, so a WHERE clause would need `DATE_ADD` on MySQL and
 * `datetime(...)` on SQLite — two dialects for one predicate, and the local
 * engine is not the one that matters. The row is fetched and judged here, exactly
 * as `BanReader` judges an expiry for the same reason.
 *
 * A stale row is left alone rather than closed: this is a read, and a read that
 * writes is a surprise for every caller. It also no longer harms anything.
 */
class EloquentFocusState implements FocusState
{
    public function isFocusing(User $user): bool
    {
        $session = FocusSession::query()
            ->where('user_id', $user->getKey())
            ->where('status', FocusSessionStatus::Running->value)
            ->orderByDesc('id')
            ->first(['started_at', 'planned_minutes']);

        if ($session === null) {
            return false;
        }

        return $session->started_at
            ->addMinutes((int) $session->planned_minutes)
            ->isFuture();
    }
}
