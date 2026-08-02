<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $rating
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
        'comment',
        'is_visible',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
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
     */
    public function studentDisplayName(): string
    {
        $student = $this->student;

        if ($student === null) {
            return 'طالب';
        }

        $surname = (string) $student->last_name;

        // Skip the definite article first. A large share of Arab family names
        // start with "ال", so taking character zero abbreviates الكواري, العطية
        // and الهاجري all to "ا." — an initial that distinguishes nobody.
        if (mb_strlen($surname) > 2 && mb_substr($surname, 0, 2) === 'ال') {
            $surname = mb_substr($surname, 2);
        }

        $initial = mb_substr($surname, 0, 1);

        return $initial === '' ? $student->first_name : $student->first_name.' '.$initial.'.';
    }
}
