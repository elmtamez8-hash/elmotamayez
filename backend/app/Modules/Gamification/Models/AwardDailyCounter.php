<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\AwardDailyCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The daily cap's reservation row.
 *
 * ⚠️ THE MODEL IS FOR READING AND FOR FACTORIES. The claim itself never goes
 * through Eloquent — it is one conditional UPDATE issued by AwardPoints, because
 * loading a model, incrementing it in PHP and saving is exactly the read-then-
 * write race the counter exists to remove.
 *
 * @property int $count
 */
class AwardDailyCounter extends BaseModel
{
    /** @use HasFactory<AwardDailyCounterFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'student_user_id',
        'day_key',
        'action_key',
        'count',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['count' => 'integer'];
    }
}
