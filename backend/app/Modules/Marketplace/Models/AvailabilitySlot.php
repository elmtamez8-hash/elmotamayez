<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\AvailabilitySlotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring weekly window a teacher is bookable in. Times are stored in UTC.
 *
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 */
class AvailabilitySlot extends BaseModel
{
    /** @use HasFactory<AvailabilitySlotFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /**
     * True when this window contains the given UTC moment.
     */
    public function coversUtc(\DateTimeInterface $moment): bool
    {
        if ((int) $moment->format('w') !== $this->day_of_week) {
            return false;
        }

        $time = $moment->format('H:i:s');

        return $time >= $this->start_time && $time < $this->end_time;
    }
}
