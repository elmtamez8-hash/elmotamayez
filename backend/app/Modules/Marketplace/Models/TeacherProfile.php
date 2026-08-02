<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPubliclyListed;
use Database\Factories\Modules\Marketplace\TeacherProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Casts are declared in casts() below; these annotations restate the resulting
 * types because Larastan infers column types from the migration, where a
 * `timestamp` reads as string and would hide the Carbon API behind it.
 *
 * @property string $approval_status
 * @property bool $is_publicly_listed
 * @property bool $is_verified
 * @property int|null $trust_score
 * @property array<string, int>|null $trust_score_factors
 * @property Carbon|null $trust_score_calculated_at
 * @property Carbon|null $first_session_at
 * @property int $completed_sessions_count
 * @property int $cancelled_sessions_count
 * @property int $students_taught_count
 * @property int $reviews_count
 * @property int $years_experience
 * @property int|null $response_rate
 * @property int|null $attendance_rate
 * @property string|null $average_rating
 * @property array<int, string>|null $qualifications
 * @property array<int, string>|null $teaching_languages
 * @property Collection<int, AvailabilitySlot> $availabilitySlots
 */
class TeacherProfile extends BaseModel
{
    /** @use HasFactory<TeacherProfileFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid, IsPubliclyListed, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUSPENDED = 'suspended';

    public const BAND_BUILDING = 'building';

    public const BAND_HIGH = 'high';

    public const BAND_MEDIUM = 'medium';

    public const BAND_LOW = 'low';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'headline',
        'bio',
        'qualifications',
        'years_experience',
        'teaching_languages',
        'hourly_rate',
        'currency',
        'photo_path',
        'is_verified',
        'approval_status',
        'is_publicly_listed',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'qualifications' => 'array',
            'teaching_languages' => 'array',
            'trust_score_factors' => 'array',
            'hourly_rate' => 'decimal:2',
            'average_rating' => 'decimal:2',
            'is_verified' => 'boolean',
            'is_publicly_listed' => 'boolean',
            'years_experience' => 'integer',
            'trust_score' => 'integer',
            'completed_sessions_count' => 'integer',
            'cancelled_sessions_count' => 'integer',
            'students_taught_count' => 'integer',
            'reviews_count' => 'integer',
            'response_rate' => 'integer',
            'attendance_rate' => 'integer',
            'trust_score_calculated_at' => 'datetime',
            'first_session_at' => 'datetime',
        ];
    }

    /**
     * A published row also has to be approved — a suspended teacher whose flag was
     * not yet recomputed must not slip through.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function publicListingConstraints(Builder $query): Builder
    {
        return $query
            ->where('teacher_profiles.is_publicly_listed', true)
            ->where('teacher_profiles.approval_status', self::STATUS_APPROVED);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_profile_subject');
    }

    /** @return BelongsToMany<GradeLevel, $this> */
    public function gradeLevels(): BelongsToMany
    {
        return $this->belongsToMany(GradeLevel::class, 'teacher_profile_grade_level');
    }

    /** @return HasMany<AvailabilitySlot, $this> */
    public function availabilitySlots(): HasMany
    {
        return $this->hasMany(AvailabilitySlot::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<Complaint, $this> */
    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    /**
     * Whether the teacher is inside one of their weekly windows right now.
     *
     * Slots are stored in UTC, so the comparison is made in UTC and only the
     * presentation layer converts to the visitor's timezone.
     */
    public function isAvailableNow(): bool
    {
        if (! $this->relationLoaded('availabilitySlots')) {
            return false;
        }

        $now = now('UTC');

        return $this->availabilitySlots->contains(
            fn (AvailabilitySlot $slot) => $slot->coversUtc($now),
        );
    }

    /**
     * Display band for the trust score. Below the data threshold the score is null
     * and the band is "building" — never "low", which would punish new teachers for
     * having no history (FR-024).
     */
    public function trustScoreBand(): string
    {
        if ($this->trust_score === null) {
            return self::BAND_BUILDING;
        }

        /** @var array{high: int, medium: int} $bands */
        $bands = config('marketplace.trust_score.bands');

        return match (true) {
            $this->trust_score >= $bands['high'] => self::BAND_HIGH,
            $this->trust_score >= $bands['medium'] => self::BAND_MEDIUM,
            default => self::BAND_LOW,
        };
    }
}
