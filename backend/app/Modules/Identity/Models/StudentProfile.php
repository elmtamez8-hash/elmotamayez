<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Support\SchoolYearDirectory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What is true of a student and of no other role.
 *
 * Platform-owned: deliberately no BelongsToWorkspace. A student's grade level is
 * one fact across every teacher they enrol with; a copy per workspace would give
 * one person several grades.
 *
 * @property int $user_id
 * @property string|null $grade_level_slug
 * @property string|null $school_year_slug
 * @property string|null $avatar_path
 * @property bool $registered_by_parent
 * @property CarbonImmutable|null $date_of_birth
 * @property bool|null $dob_is_estimated
 * @property CarbonInterface|null $ownership_transferred_at
 * @property string|null $guardian_contact
 * @property int|null $region_id
 */
class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'grade_level_slug',
        // Stored path on the `public` disk, never a URL: the host changes between
        // environments and a saved absolute URL is a broken image after the first
        // deploy. It becomes `asset('storage/'.$path)` at the edge of the payload.
        'avatar_path',
        'registered_by_parent',
        /*
        | ⚠️ THESE THREE SHIPPED IN THE MIGRATION AND NOT IN THIS LIST, AND THE
        | RESULT WAS THAT NO SELF-REGISTERED STUDENT HAD A DATE OF BIRTH AT ALL.
        | `RegisterStudent` passes all three to `create()`, and mass assignment
        | DISCARDS a non-fillable key in silence — no exception, no log, a 201 and
        | a profile row with three nulls in it. Which means: the guardian-consent
        | gate that FR-009 hangs on the student's age never fired for anyone who
        | signed up themselves, the contact they typed for their guardian was
        | thrown away, and the coming-of-age sweep below would walk an empty set
        | for ever. Found by measurement while writing that sweep, not by the
        | suite — every US1 assertion was made against the response body or the
        | relations table, and both were correct.
        */
        'date_of_birth',
        'dob_is_estimated',
        'guardian_contact',
        /*
        | Spec 011 · FR-042. In this list from its first day, for the reason the
        | three above it are: a column added to the migration and not to this
        | array is written by nobody, silently.
        */
        'region_id',
        /*
        | Spec 022 · FR-005 — the individual school year. In this list from its
        | first day, for the reason the block above it exists: `RegisterStudent`
        | writes through `create()`, and mass assignment discards a non-fillable
        | key with no exception and no log line.
        */
        'school_year_slug',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'registered_by_parent' => 'boolean',
            // `immutable_date`, so the value is a date and never a moment: an age
            // compared against a timestamp is off by up to a day at the one
            // boundary that decides whether a person can sign for themselves.
            'date_of_birth' => 'immutable_date',
            'dob_is_estimated' => 'boolean',
            'ownership_transferred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The region this student registered from.
     *
     * ⚠️ THE COLUMN IS THE FK AND EVERY PAYLOAD CARRIES THE SLUG — the same
     * split `grade_level_slug` keeps: an autoincrement id never travels here,
     * and the settings form that lets a student correct their region needs the
     * slug to pre-select the option they already have.
     *
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * The broad stage this student is in.
     *
     * ⚠️ EVERY READER CALLS THIS. `school_year_slug` is what a new registration
     * writes; `grade_level_slug` is what everybody who registered before years
     * existed has. Repeating `?? $profile->grade_level_slug` at a call site is
     * the two-answers defect wearing the clothes of a fix — the first place
     * anybody forgets shows a stage that disagrees with the one beside it, with
     * no error anywhere.
     *
     * ⚠️ AND IT IS PER-ROW. A LIST reads
     * {@see SchoolYearDirectory} instead, which
     * takes the whole map in one query: a Resource runs once per row, so a lookup
     * inside one is an N+1 by construction.
     */
    public function stageSlug(): ?string
    {
        return SchoolYear::stageFor($this->school_year_slug, $this->grade_level_slug);
    }
}
