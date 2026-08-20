<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Filament\Resources\GradeLevelResource;
use App\Modules\Marketplace\Filament\Resources\SubjectResource;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Support\Facades\Gate;

/**
 * The taxonomy is the platform's, and until now nothing said so in code.
 *
 * ⚠️ `taxonomy.manage` WAS DECLARED IN 009 AND CHECKED IN NO FILE — the promotion
 * migration named it, `CatalogPermissionTest` proved it was classified
 * platform-level, and every one of those passed while the rows themselves had no
 * guard at all. `RolePermissionMatrix::platformPermissions()` derives the platform
 * set by ABSENCE, which is exactly the property that can be perfectly true about a
 * permission nobody calls.
 *
 * This file is the other half: the permission now DECIDES something, in both
 * directions.
 */
it('refuses the workspace owner, the highest tenant role there is', function (): void {
    [, $owner] = $this->createWorkspaceWithOwner();

    // Checked by name rather than against a role picked at random: if the owner
    // cannot, nobody below them can. One "الرياضيات" serves the whole platform
    // since 009, so a teacher editing it edits it for every teacher.
    expect($owner->can(Permissions::TAXONOMY_MANAGE))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', Subject::factory()->create()))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('viewAny', Subject::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', GradeLevel::class))->toBeFalse();
});

/*
 * The allow direction, which is the half that catches a policy wired to nothing.
 *
 * ⚠️ WITHOUT IT THIS FILE WOULD PASS AGAINST A MISSING `Gate::policy()` LINE.
 * Laravel's guesser looks for SubjectPolicy and GradeLevelPolicy and finds
 * neither, so an unregistered TaxonomyPolicy leaves "no policy applies" — and
 * every deny assertion above stays green while the screen is open to anyone.
 */
it('admits a super admin to both models', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    expect(Gate::forUser($admin)->allows('viewAny', Subject::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', Subject::factory()->create()))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', GradeLevel::factory()->create()))->toBeTrue();
});

/*
 * And nobody deletes — including the super admin, which the POLICY cannot say.
 *
 * ⚠️ `AppServiceProvider` INSTALLS `Gate::before(fn ($user) => $user->isSuperAdmin()
 * ? true : null)`, WHICH SHORT-CIRCUITS EVERY POLICY METHOD — `delete()` included.
 * So `TaxonomyPolicy::delete()` is the guard for everybody EXCEPT the one account
 * most likely to be sitting in `/admin` when the button is pressed, and asserting
 * the policy against a super admin fails. `CataloguePolicy` has the identical
 * property and no test names it.
 *
 * The universal guard is therefore the RESOURCE, which is also what stops Filament
 * rendering a button that would then be refused — a refusal on click reads as a
 * broken panel rather than as a rule. Asserted on the concrete classes, not the
 * abstract parent: a subclass can override, and these are the two that ship.
 *
 * The slug is a denormalised join key in three places and none of them is a
 * foreign key the database would defend: `courses.grade_level`,
 * `student_profiles.grade_level_slug`, and every stored `grade:{slug}`
 * leaderboard key. A delete leaves all three naming a vocabulary entry that no
 * longer exists.
 */
it('offers no delete on either screen', function (): void {
    expect(SubjectResource::canDeleteAny())->toBeFalse()
        ->and(SubjectResource::canDelete(Subject::factory()->create()))->toBeFalse()
        ->and(GradeLevelResource::canDeleteAny())->toBeFalse()
        ->and(GradeLevelResource::canDelete(GradeLevel::factory()->create()))->toBeFalse();
});

/*
 * And the policy's own answer, called directly rather than through the Gate.
 *
 * ⚠️ THE DENY IS CURRENTLY UNREACHABLE THROUGH `Gate`, AND THAT IS WORTH WRITING
 * DOWN RATHER THAN DRESSING UP. `taxonomy.manage` is held by exactly one role —
 * SUPER_ADMIN, the `$all` row of the matrix — and a super admin is short-circuited
 * by `Gate::before` before any policy runs. Granting the permission to anyone else
 * is impossible by construction: spatie's `model_has_permissions.team_id` is NOT
 * NULL, which is the very reason `platform_staff` exists.
 *
 * So the assertion is against the method, not the façade. It cannot fail today; it
 * fails the day someone adds a taxonomy officer to the matrix and flips this deny
 * to an allow in the same breath — which is exactly when nobody would be looking.
 */
it('answers deny from the policy itself, for any caller', function (): void {
    [, $owner] = $this->createWorkspaceWithOwner();
    $policy = app(TaxonomyPolicy::class);

    expect($policy->delete($owner, Subject::factory()->create())->allowed())->toBeFalse()
        ->and($policy->delete($owner, GradeLevel::factory()->create())->allowed())->toBeFalse();
});

/*
 * The edit has to reach the public marketplace, which caches for up to a minute.
 *
 * A subject retired because it is wrong would keep being offered until the TTL
 * lapsed, and a corrected name would look like a save that did not take. The
 * flush is on the MODEL rather than in the Filament page, so a seeder or a later
 * endpoint gets it too.
 */
it('invalidates the public taxonomy cache when a row is saved', function (): void {
    $subject = Subject::factory()->create();
    $before = MarketplaceCache::version();

    $subject->update(['name_ar' => 'الرياضيات المتقدّمة']);

    expect(MarketplaceCache::version())->toBeGreaterThan($before);

    $grade = GradeLevel::factory()->create();
    $between = MarketplaceCache::version();

    $grade->update(['is_active' => false]);

    expect(MarketplaceCache::version())->toBeGreaterThan($between);
});
