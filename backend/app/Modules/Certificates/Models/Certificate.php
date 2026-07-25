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
        'exam_attempt_id',
        'issue_reason',
        'issued_at',
        'template_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('certificate_pdf')->singleFile();
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class);
    }
}
