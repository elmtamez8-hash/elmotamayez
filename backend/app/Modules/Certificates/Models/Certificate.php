<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $issue_reason
 */
class Certificate extends BaseModel implements HasMedia
{
    use BelongsToWorkspace, HasUuid, InteractsWithMedia;

    protected $fillable = [
        'workspace_id',
        'certificate_number',
        'verification_code',
        'enrollment_id',
        'course_id',
        'student_user_id',
        'student_display_name',
        'teacher_display_name',
        'subject_display_name',
        'exam_attempt_id',
        'issue_reason',
        'issued_at',
        'metadata',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        // ⚠️ `local`, never the default: medialibrary falls back to `MEDIA_DISK`
        // which defaults to `public`, and nginx serves `/storage/` straight off
        // that disk — a permanent, unauthenticated URL to a named minor's PDF.
        // Measured on production 2026-09-25: 9 report cards answered 200 to an
        // anonymous GET. The signed, authenticated route is the only door.
        $this->addMediaCollection('certificate_pdf')->singleFile()->useDisk('local');
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
