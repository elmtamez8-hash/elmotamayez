<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Support\DisplayName;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Marketplace\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $rating
 * @property int|null $punctuality
 * @property int|null $clarity
 * @property int|null $engagement
 * @property CarbonInterface $period_start
 * @property bool $is_visible
 * @property string|null $comment
 * @property-read User|null $student
 */
class Review extends BaseModel
{
    /** @use HasFactory<ReviewFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'student_id',
        'rating',
        'punctuality',
        'clarity',
        'engagement',
        'period_start',
        'comment',
        'is_visible',
    ];

    /** The three axes FR-031 names, in the order every screen reads them. */
    public const AXES = ['punctuality', 'clarity', 'engagement'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            // ⚠️ NULLABLE INTEGERS, AND THE NULL IS THE POINT. Every review written
            // before spec 010 has three of these empty, and averaging them as zero
            // would drag `average_rating` — and the trust score behind it — to the
            // floor for every teacher on the platform. `SC-011` measures exactly
            // that, which is why nothing here reads an axis without a null check.
            'punctuality' => 'integer',
            'clarity' => 'integer',
            'engagement' => 'integer',
            /*
            | ⚠️ `date:Y-m-d`, NEVER A BARE `date`, BECAUSE THIS COLUMN IS PART OF A
            | UNIQUE KEY. Eloquent writes a bare date-cast attribute through the
            | model's DATETIME format, so the stored value is `2026-08-24 00:00:00`
            | on SQLite and `2026-08-24` on MySQL — the column is DATE and MySQL
            | truncates on insert. `firstOrNew([... 'period_start' => '2026-08-24'])`
            | therefore MISSES its own row on SQLite and matches on MySQL: the same
            | write is a revision in production and a unique-constraint 500 locally,
            | which is the worst shape a bug can have. The format cast makes the
            | stored value the same string on both.
            |
            | Measured, not assumed: `date` stores `2026-08-24 00:00:00` and
            | `date:Y-m-d` stores `2026-08-24`. The FreezePeriod::covering() note in
            | LiveSessions records the same fact from the query side.
            */
            'period_start' => 'date:Y-m-d',
            'is_visible' => 'boolean',
        ];
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * "أحمد م." — enough to show a real person left this, not enough to identify
     * them to the teacher they just rated (FR-021).
     *
     * The rule itself moved to {@see DisplayName} in spec 009, because the
     * leaderboard needs the same abbreviation and this is an instance method on a
     * Marketplace model. Two implementations of one rule diverge at the first fix.
     */
    public function studentDisplayName(): string
    {
        return DisplayName::forStudent($this->student);
    }
}
