<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 * @property int $random_seed
 */
class Attempt extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $table = 'exam_attempts';

    protected $fillable = [
        'workspace_id',
        'exam_id',
        'enrollment_id',
        'student_user_id',
        'status',
        'score',
        'max_score',
        'passed',
        'random_seed',
        'started_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'passed' => 'boolean',
            'random_seed' => 'integer',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'attempt_id');
    }

    public function isGraded(): bool
    {
        return $this->status === 'graded';
    }
}
