<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Data;

use App\Modules\Gamification\Actions\AwardPoints;
use App\Shared\Data\DataTransferObject;

/**
 * One award, fully described.
 *
 * ⚠️ IT CARRIES NO VALUES. A listener names the ACTION and the CONTEXT; what the
 * action is worth is read from the catalogue inside {@see AwardPoints}
 * (NFR-002). A value computed in a listener is the value nobody can find when
 * they go looking for it on the panel — and it would make FR-002 ("editable
 * without a deploy") false for that one path, silently.
 *
 * `sourceType` and `sourceId` are the idempotency key together with the student
 * and the action, so both must identify the CAUSE and not the moment: passing a
 * timestamp there makes every redelivery a fresh award.
 */
final class AwardRequest extends DataTransferObject
{
    public function __construct(
        public readonly int $studentUserId,
        public readonly string $actionKey,
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly ?int $workspaceId = null,
        public readonly ?int $courseId = null,
        public readonly ?int $lessonId = null,
    ) {}
}
