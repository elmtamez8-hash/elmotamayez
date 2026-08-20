<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Shared\Contracts\FreezeDirectory;
use Carbon\CarbonImmutable;

/**
 * LiveSessions owns the freeze period; Gamification asks through the interface
 * rather than reaching into this model (Constitution III). Same binding shape as
 * {@see EloquentSessionAttendanceDirectory}.
 */
class EloquentFreezeDirectory implements FreezeDirectory
{
    public function isFrozenForStudent(int $studentUserId, string $dayKey): bool
    {
        /*
        | ⚠️ withoutWorkspaceScope(), AND IT IS THE WHOLE CORRECTNESS OF THIS
        | METHOD. A streak is platform-owned — one per student across every
        | teacher they study with — while a freeze period is workspace-scoped.
        | Left scoped, this would answer for whichever workspace the context
        | happened to resolve to; and for a student that is null, so the scope
        | adds no filter and the right answer would come out by accident until the
        | first time it was called from somewhere with a context.
        |
        | Reusing `scopeCovering` rather than rewriting the date comparison: its
        | bounds carry two fixes (the index-preserving plain comparison, and the
        | next-day upper bound that a DATE column stored as a datetime needs).
        */
        return FreezePeriod::query()
            ->withoutWorkspaceScope()
            ->covering(CarbonImmutable::parse($dayKey), $studentUserId)
            ->exists();
    }
}
