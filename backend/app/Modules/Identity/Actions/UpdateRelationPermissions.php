<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Shared\Actions\Action;
use App\Shared\Support\GuardianPermission;
use DomainException;

/**
 * What a guardian may see, changed by one of the two people it is about.
 *
 * ⚠️ NARROWING IS EITHER PARTY'S RIGHT; WIDENING IS THE STUDENT'S ALONE
 * (spec 030 · FR-009). Without that split, accepting a link is a blank cheque:
 * the student agrees to «attendance» and the guardian then grants themselves
 * «payments» and «results» with one request against their own row — because
 * `ParentStudentRelationPolicy::update` is `isParty`, i.e. both sides.
 *
 * ⚠️ AND BOTH CHECKS LIVE HERE RATHER THAN IN THE FORM REQUEST OR THE POLICY.
 * The Action is the shared entrance for the API, seeders and Filament
 * (Constitution II) — and, more sharply, `AppServiceProvider`'s `Gate::before`
 * waves a super admin past every policy method, so a rule written only in the
 * policy leaves exactly one actor able to widen a guardian's reach over a
 * student who never agreed to it. The party check below is what stops them.
 */
class UpdateRelationPermissions extends Action
{
    /** @param list<GuardianPermission> $permissions */
    public function handle(User $actor, ParentStudentRelation $relation, array $permissions): ParentStudentRelation
    {
        $actorId = (int) $actor->getKey();
        $isGuardian = $actorId === (int) $relation->guardian_user_id;
        $isStudent = $actorId === (int) $relation->student_user_id;

        if (! $isGuardian && ! $isStudent) {
            throw new DomainException('لا يمكنك تعديل صلاحيّات هذا الرابط.');
        }

        $values = array_values(array_unique(
            array_map(static fn (GuardianPermission $p): string => $p->value, $permissions),
        ));

        if ($isGuardian && array_diff($values, $relation->permissions) !== []) {
            throw new DomainException('لا يمكنك منح نفسك صلاحية جديدة. التوسيع قرار الطالب.');
        }

        $relation->forceFill([
            'permissions' => $values,
        ])->save();

        return $relation;
    }
}
