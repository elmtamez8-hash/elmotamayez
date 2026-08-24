<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Community\PeriodicReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The teacher's assessment of one student over one period (FR-028).
 *
 * @property int $commitment
 * @property int $participation
 * @property int $homework
 * @property int $improvement
 * @property string|null $note
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property CarbonInterface|null $published_at
 * @property-read User|null $student
 * @property-read User|null $teacher
 */
class PeriodicReview extends BaseModel
{
    /** @use HasFactory<PeriodicReviewFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    /**
     * ⚠️ `published_at` IS DELIBERATELY ABSENT. It is claimed by a conditional
     * UPDATE inside `PublishPeriodicReview` — mass-assignable, it becomes a second
     * way to publish from outside the statement that owns the claim, and the
     * notification then goes out twice. Same rule as `captured_order_id`.
     */
    protected $fillable = [
        'workspace_id',
        'student_user_id',
        'teacher_user_id',
        'period_start',
        'period_end',
        'commitment',
        'participation',
        'homework',
        'improvement',
        'note',
    ];

    /** The four axes, in the order every screen and every payload reads them. */
    public const AXES = ['commitment', 'participation', 'homework', 'improvement'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            // ⚠️ `date:Y-m-d`, NEVER A BARE `date`. Both columns are part of the
            // unique quadruple, and a bare date cast is written through the
            // model's DATETIME format — `2026-08-24 00:00:00` on SQLite, truncated
            // to `2026-08-24` by MySQL. `firstOrNew()` on the pair would then miss
            // its own row locally and find it in production. See the longer note
            // on `Review::casts()`.
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'published_at' => 'datetime',
            'commitment' => 'integer',
            'participation' => 'integer',
            'homework' => 'integer',
            'improvement' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** The four axes averaged, to one decimal — a summary, never a stored column. */
    public function average(): float
    {
        return round(
            ($this->commitment + $this->participation + $this->homework + $this->improvement) / 4,
            1,
        );
    }
}
