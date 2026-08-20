<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Policies;

use App\Models\User;
use App\Modules\Gamification\Policies\CataloguePolicy;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Subjects and grade levels — platform reference data (constitution v1.2.0 §I,
 * kind ب), and until now a permission that guarded nothing at all.
 *
 * ⚠️ `taxonomy.manage` WAS DECLARED IN 009 AND CHECKED IN NO FILE. The migration
 * that promoted the taxonomy says in its own docblock that writing "becomes the
 * platform permission `taxonomy.manage`", and {@see CatalogPermissionTest} proves
 * the constant is classified platform-level — but a permission nothing calls is a
 * sentence in a comment. `RolePermissionMatrix::platformPermissions()` derives the
 * platform set by ABSENCE, so the constant passed every test while the rows it
 * names had no guard and no screen.
 *
 * ⚠️ ONE POLICY FOR TWO MODELS, on purpose — {@see CataloguePolicy} in the same
 * words. Both answer the same question with the same permission, and two files
 * differing only in a type-hint is two places for the next person to change one.
 *
 * ⚠️ AND THE WORKSPACE OWNER MUST FAIL IT. There is one "الرياضيات" for the whole
 * platform since 009; a teacher editing this row edits it for every teacher, and
 * renaming or retiring a subject reshapes the marketplace's own filters and the
 * cross-workspace `subject:` and `grade:` leaderboard boards for everybody.
 *
 * Reading is the same permission as writing, following {@see CataloguePolicy}: the
 * screen lists INACTIVE rows, which is the platform's own retired vocabulary. The
 * student's and the visitor's view never comes through here — that is
 * `ListPublicTaxonomy`, which shows active rows only and needs no permission.
 */
class TaxonomyPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->create($user);
    }

    public function view(User $user, Model $record): Response
    {
        return $this->create($user);
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::TAXONOMY_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Model $record): Response
    {
        return $this->create($user);
    }

    /**
     * Retire, never erase.
     *
     * ⚠️ THE SLUG IS A DENORMALISED JOIN KEY IN THREE PLACES, none of them a
     * foreign key the database would defend: `courses.grade_level` is text,
     * `student_profiles.grade_level_slug` is text, and every stored
     * `leaderboard_entries.scope_key` of the form `grade:{slug}` is text. Deleting
     * the row leaves all three pointing at a vocabulary entry that no longer
     * exists — a course filed under a stage the marketplace cannot name, and a
     * board whose title has no source.
     *
     * {@see Subject} is bound by pivots instead, so a delete there would be
     * refused by the database rather than silently — a different failure for the
     * same mistake, which is why one rule covers both.
     *
     * `is_active = false` is what every read already consults.
     *
     * @see GradeLevel
     */
    public function delete(User $user, Model $record): Response
    {
        return Response::deny('يُعطَّل العنصر ولا يُحذف: الكورساتُ والطلابُ ولوحاتُ الصدارة تشير إليه بمُعرِّفه النصّي.');
    }
}
