<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\LiveSessions\ClassSessionFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The teacher's short remark on one student in one session.
 *
 * Optional by design: the post-session report goes out on time with attendance
 * alone rather than waiting for a remark that may never come (FR-035).
 */
class ClassSessionFeedback extends BaseModel
{
    /** @use HasFactory<ClassSessionFeedbackFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $table = 'class_session_feedback';

    protected $fillable = [
        'workspace_id',
        'class_session_id',
        'student_user_id',
        'rating',
        'note',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    /** @return BelongsTo<ClassSession, $this> */
    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
