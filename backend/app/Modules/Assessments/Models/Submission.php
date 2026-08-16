<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What one student handed in for one assignment — or did not.
 *
 * ⚠️ A ROW EXISTS EVEN WHEN NOTHING WAS HANDED IN. The nightly sweep writes
 * `missed` for everyone who let the deadline pass (FR-051), which is what makes
 * "who is behind" a query rather than a comparison of two lists — and what makes
 * the unique index below a real point of contention, since the student may still
 * hand in afterwards against that very row.
 *
 * ⚠️ THE FILE LIVES ON THE PRIVATE DISK, on the receipts' precedent. It is never
 * served from a public path and never gets a permanent url: `getFirstMediaUrl()`
 * on a disk with no `url` in config falls back to the conventional
 * `/storage/{id}/{file}`, which serves the PUBLIC disk — every link 403s, and any
 * that worked would be a student's coursework on a guessable address.
 *
 * @property string $state
 * @property int $late_by_minutes
 */
class Submission extends BaseModel implements HasMedia
{
    use BelongsToWorkspace, HasUuid, InteractsWithMedia;

    public const STATE_ON_TIME = 'on_time';

    public const STATE_LATE = 'late';

    public const STATE_MISSED = 'missed';

    /**
     * Nothing handed in, and nothing late about it yet.
     *
     * ⚠️ AN EXTENSION HAS TO WRITE A ROW BEFORE THE WORK ARRIVES — it has
     * nowhere else to live — and that row was being born labelled `missed`. It
     * is read by US7's unlock gate, so a student told in writing that they had
     * until Thursday was locked out of Wednesday's session by the very act of
     * granting them the extra days. `missed` is a VERDICT; this is the absence
     * of one.
     */
    public const STATE_PENDING = 'pending';

    protected $fillable = [
        'workspace_id',
        'assignment_id',
        'student_user_id',
        'state',
        'answer_text',
        'submitted_at',
        'late_by_minutes',
        'late_penalty_applied_pct',
        'extension_until',
        'score',
        'feedback',
        'graded_at',
        'graded_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'extension_until' => 'datetime',
            'graded_at' => 'datetime',
            'late_by_minutes' => 'integer',
            'late_penalty_applied_pct' => 'decimal:2',
            'score' => 'decimal:2',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('submission')
            ->useDisk('local')
            ->singleFile();
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function wasSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function isGraded(): bool
    {
        return $this->graded_at !== null;
    }

    public function file(): ?Media
    {
        return $this->getFirstMedia('submission');
    }

    /**
     * Claim this row for a hand-in, atomically.
     *
     * ⚠️ THE SWEEP MAY HAVE WRITTEN IT FIRST. A student with an extension hands
     * in the morning after the deadline swept: a blind `create()` collides with
     * `unique(assignment_id, student_user_id)` and a blind `update()` is the
     * two-tap race — both taps read "not submitted" and both write. The same
     * idiom as seat allocation and `StructureVersion::claim()`; false is the
     * refusal, and here it means "already handed in", which the Action turns
     * into a resubmission rather than an error.
     *
     * @param  array<string, mixed>  $values
     */
    public function claimForSubmission(array $values): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->whereNull('submitted_at')
            ->update($values) === 1;
    }
}
