<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Data;

use App\Modules\Gamification\Enums\LeaderboardPeriod;
use App\Modules\Gamification\Support\LeaderboardScope;
use App\Shared\Data\DataTransferObject;

/**
 * A resolved leaderboard request: which scope, which period.
 *
 * The scope arrives as `subject:{uuid}` or `grade:{slug}` and is resolved to the
 * stored key here, so no controller ever builds a `scope_key` by hand.
 */
final class LeaderboardQuery extends DataTransferObject
{
    public function __construct(
        public readonly LeaderboardScope $scope,
        /** The stored `scope_key` — `platform`, `subject:12`, `grade:secondary`… */
        public readonly string $scopeKey,
        /** Echoed back to the client in the form it sent, never the stored one. */
        public readonly string $requestedScope,
        public readonly LeaderboardPeriod $period,
        public readonly string $periodKey,
    ) {}
}
