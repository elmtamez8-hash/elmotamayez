<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\GradeLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * PLATFORM reference data since spec 009 — see {@see Subject} for why the
 * workspace column had to go, and why it must not come back.
 *
 * @property string $slug
 * @property string $name
 */
class GradeLevel extends BaseModel
{
    /** @use HasFactory<GradeLevelFactory> */
    use HasFactory, HasTranslations, HasUuid;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'name',
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
