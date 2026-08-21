<?php

declare(strict_types=1);

namespace App\Modules\Media\Events;

use App\Modules\LiveSessions\Listeners\ArchiveExpiredRecordingLessons;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A batch of assets whose retention has run out (spec 013 · FR-031ب).
 *
 * ⚠️ A BATCH, NOT ONE ASSET, AND THAT IS THE WHOLE DESIGN OF THIS CLASS. Fifty
 * recordings of one course expiring on one night would fire fifty listeners, each
 * running a FULL progress resync over the same set of enrolments — and that, not
 * the deletes, is what threatens the sweep's timeout. Carrying the batch lets the
 * listener resolve its courses and resync each ONCE.
 *
 * ⚠️ AND IT CARRIES THE OWNER PAIR, so `Media` needs to know nothing about who
 * owns an asset. Each listener claims the `owner_type` it recognises and ignores
 * the rest — which is how a recording's LESSON gets archived and
 * `CourseStructureChanged` gets fired without this module importing a model from
 * either of the two that care.
 *
 * @see ArchiveExpiredRecordingLessons
 */
class MediaAssetsExpired
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<array{id: int, owner_type: string, owner_id: int}>  $assets
     */
    public function __construct(public readonly array $assets) {}

    /**
     * The owner ids of one polymorphic type.
     *
     * @return list<int>
     */
    public function ownerIdsOf(string $ownerType): array
    {
        $ids = [];

        foreach ($this->assets as $asset) {
            if ($asset['owner_type'] === $ownerType) {
                $ids[] = $asset['owner_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
