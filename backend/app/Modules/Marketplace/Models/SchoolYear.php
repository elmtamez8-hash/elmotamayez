<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use App\Modules\Marketplace\Support\SchoolYearDirectory;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\SchoolYearFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The individual year a student is in (spec 022 · FR-001ب).
 *
 * Platform reference data, like {@see Region}: no workspace column at all, the
 * write door is {@see TaxonomyPolicy} +
 * `taxonomy.manage`, and the read door is public and unauthenticated because the
 * registration form needs the list before there is an account.
 *
 * @property int $id
 * @property int $grade_level_id
 * @property string $slug
 * @property string $name_ar
 * @property bool $is_active
 * @property-read GradeLevel $gradeLevel
 */
class SchoolYear extends BaseModel
{
    /** @use HasFactory<SchoolYearFactory> */
    use HasFactory, HasUuid;

    /**
     * ⚠️ `grade_level_id` IS IN THIS LIST AND ITS THREE SIBLINGS HAVE NO FOREIGN
     * KEY AT ALL. Copying `$fillable` from `Subject`, `GradeLevel` or `Region`
     * drops it, and mass assignment discards a non-fillable key in SILENCE — a
     * seeded or panel-created year would land with a NOT NULL violation at best
     * and, on a nullable column, with nothing at all.
     */
    protected $fillable = [
        'grade_level_id',
        'name_ar',
        'slug',
        'sort_order',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'grade_level_id' => 'integer',
        ];
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /**
     * "Actually on offer" — the year is active AND its stage is.
     *
     * ⚠️ THIS IS THE ONE PREDICATE. The public read uses it and so does every
     * `Rule::in` on every signup form, because a door that accepts what the
     * screen does not show is two answers to one question — which is exactly the
     * state `RegisterStudentRequest` was in before this spec, validating stages
     * against `is_active` alone while the screen showed a participation-filtered
     * subset.
     *
     * It is read, never written. A cascading deactivation would write into rows
     * that nothing puts back when the stage is re-enabled.
     *
     * @param  Builder<SchoolYear>  $query
     * @return Builder<SchoolYear>
     */
    public function scopeActivelyOffered(Builder $query): Builder
    {
        return $query
            ->where('school_years.is_active', true)
            ->whereHas('gradeLevel', fn (Builder $stage) => $stage->where('is_active', true));
    }

    /**
     * The broad stage of a student described by a year slug, or by the legacy
     * stage column when they registered before years existed.
     *
     * ⚠️ TWO STRINGS, NOT A MODEL, and that is not surplus abstraction:
     * `parent_student_relations.student_user_id` is NULLABLE — a guardian may add
     * a child with no account at all — so there is no `StudentProfile` there to
     * ask. A method on the profile alone would force a second derivation onto the
     * relation, which is the two-answers defect this spec exists to avoid.
     *
     * ⚠️ AND IT IS THE ONLY DERIVATION. Repeating `?? $profile->grade_level_slug`
     * at a call site is that same defect wearing the clothes of a fix: the first
     * place anybody forgets shows a stage that disagrees with the one beside it,
     * with no error anywhere.
     *
     * Per-row here; {@see SchoolYearDirectory}
     * is the bulk form a list must use.
     */
    public static function stageFor(?string $yearSlug, ?string $legacyStageSlug = null): ?string
    {
        if ($yearSlug === null) {
            return $legacyStageSlug;
        }

        return app(SchoolYearDirectory::class)->stageFor($yearSlug)
            ?? $legacyStageSlug;
    }
}
