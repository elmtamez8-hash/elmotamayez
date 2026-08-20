<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\StudentProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per student, across every teacher they study with — PLATFORM-owned
 * (constitution v1.2.0 §I, layer أ · FR-028أ · SC-017).
 *
 * ⚠️ NO BelongsToWorkspace, AND IT MUST NEVER GAIN ONE. With the trait, a student
 * enrolled with three teachers would have three levels, three streaks and three
 * badge sets, and would watch their own level drop by switching context. That is
 * the mirror image of the bug on the other side — coin_balances DOES carry the
 * trait, because the shop is the teacher's and the coins are spent there.
 *
 * ⚠️ AND EVERY WRITE TO IT IS A CONDITIONAL, MONOTONIC UPDATE issued from
 * ProgressWriter — never `$model->x = y; $model->save()`. Read-modify-write here
 * loses awards under any concurrency at all, and the conditions are what make the
 * operations idempotent (research §R9).
 *
 * @property int $xp
 * @property int $level
 * @property int $current_streak
 * @property int $best_streak
 * @property int $shield_count
 * @property int $notified_level
 * @property string|null $last_active_day
 * @property string|null $streak_evaluated_day
 */
class StudentProgress extends BaseModel
{
    /** @use HasFactory<StudentProgressFactory> */
    use HasFactory, HasUuid;

    protected $table = 'student_progress';

    protected $fillable = [
        'user_id',
        'xp',
        'level',
        'current_streak',
        'best_streak',
        'last_active_day',
        'streak_evaluated_day',
        'shield_count',
        'notified_level',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'level' => 'integer',
            'current_streak' => 'integer',
            'best_streak' => 'integer',
            'shield_count' => 'integer',
            'notified_level' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
