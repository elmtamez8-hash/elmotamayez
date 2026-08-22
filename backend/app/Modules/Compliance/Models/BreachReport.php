<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Models\BaseModel;
use App\Modules\Compliance\Enums\BreachStatus;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;

/**
 * A reported breach — PLATFORM reference data (layer ب).
 *
 * ⚠️ `$fillable` CARRIES ONLY WHAT A REPORTER MAY WRITE. The scope fields — which
 * categories, how many people — are filled in by staff during triage, and
 * accepting them from a public unauthenticated route would let anyone assert the
 * size of an incident into our own record of it.
 *
 * @property int|null $reported_by_user_id
 * @property string|null $reporter_contact
 * @property string $description
 * @property BreachStatus $status
 * @property list<string>|null $affected_categories
 * @property int|null $affected_subject_count
 * @property CarbonImmutable|null $authority_notified_at
 * @property CarbonImmutable|null $subjects_notified_at
 * @property CarbonImmutable|null $closed_at
 */
class BreachReport extends BaseModel
{
    use HasUuid;

    /** @var list<string> */
    protected $fillable = [
        'reported_by_user_id',
        'reporter_contact',
        'description',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => BreachStatus::class,
            'affected_categories' => 'array',
            'affected_subject_count' => 'integer',
            'authority_notified_at' => 'immutable_datetime',
            'subjects_notified_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
