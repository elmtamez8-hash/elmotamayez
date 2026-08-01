<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\GradeLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $slug
 * @property string $name_ar
 */
class GradeLevel extends BaseModel
{
    /** @use HasFactory<GradeLevelFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'name_ar',
        'slug',
        'icon',
        'sort_order',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsToMany<TeacherProfile, $this> */
    public function teacherProfiles(): BelongsToMany
    {
        return $this->belongsToMany(TeacherProfile::class, 'teacher_profile_grade_level');
    }
}
