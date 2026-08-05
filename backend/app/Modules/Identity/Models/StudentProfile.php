<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What is true of a student and of no other role.
 *
 * Platform-owned: deliberately no BelongsToWorkspace. A student's grade level is
 * one fact across every teacher they enrol with; a copy per workspace would give
 * one person several grades.
 *
 * @property string|null $grade_level_slug
 * @property bool $registered_by_parent
 */
class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'grade_level_slug',
        'registered_by_parent',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'registered_by_parent' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
