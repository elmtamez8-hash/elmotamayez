<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\SubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * PLATFORM reference data since spec 009 (constitution v1.2.0 §I, layer ب).
 *
 * ⚠️ NO BelongsToWorkspace, AND IT MUST NOT REGAIN ONE. It carried the trait
 * until 009, which meant "الرياضيات" was a different row with a different id for
 * every teacher — so the marketplace had to fold results on slug by hand, and the
 * cross-workspace subject leaderboard scope would have silently been a
 * per-workspace one. The constitution names this exact failure: reference data
 * given a workspace_id "copies itself once per tenant and diverges at the first
 * edit".
 *
 * Writing is the platform permission `taxonomy.manage`; a teacher editing this
 * row edits it for everybody.
 *
 * @property string $slug
 * @property string $name_ar
 */
class Subject extends BaseModel
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
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
        return $this->belongsToMany(TeacherProfile::class, 'teacher_profile_subject');
    }
}
