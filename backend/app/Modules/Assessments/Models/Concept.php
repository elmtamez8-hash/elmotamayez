<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Assessments\ConceptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A knowledge unit a question is tagged with.
 *
 * The most important of the four tags, and the reason all four are mandatory:
 * spec 012's adaptive path is built entirely on concept and difficulty, so a
 * bank tagged loosely today is a feature that cannot be built later.
 *
 * Workspace-owned. Concepts are not shared between teachers in this phase —
 * one teacher's "المشتقّات" is their own taxonomy, and merging taxonomies is a
 * product decision nobody has made.
 *
 * @property string $name
 */
class Concept extends BaseModel
{
    /** @use HasFactory<ConceptFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    /** The row every pre-008 question was assigned to. */
    public const UNCLASSIFIED = 'غير مصنّف';

    protected $fillable = [
        'workspace_id',
        'subject_id',
        'name',
        'created_by',
    ];

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
