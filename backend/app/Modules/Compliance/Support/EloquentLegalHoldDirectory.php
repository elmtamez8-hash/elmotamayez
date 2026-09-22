<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\LegalHold;
use App\Shared\Contracts\LegalHoldDirectory;

/**
 * The only implementation of {@see LegalHoldDirectory}.
 *
 * ⚠️ NOT MEMOISED, AND THAT IS THE POINT. Its sibling directories cache because
 * they answer the same question many times inside one request; this one is asked
 * once per batch of an irreversible walk, deliberately, so that a hold placed
 * while the job is running protects the rows it has not reached yet. A cached
 * answer would hand the caller the world as it was when the job started.
 *
 * ⚠️ `legal_holds` carries no `workspace_id` — a hold is a platform fact about a
 * person — so there is no scope to bypass here and none is written.
 */
final class EloquentLegalHoldDirectory implements LegalHoldDirectory
{
    /** @return list<int> */
    public function heldUserIds(): array
    {
        /** @var list<int> */
        return LegalHold::query()
            ->inForce()
            ->pluck('subject_user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
