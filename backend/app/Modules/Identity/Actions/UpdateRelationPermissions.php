<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\ParentStudentRelation;
use App\Shared\Actions\Action;
use App\Shared\Support\GuardianPermission;

class UpdateRelationPermissions extends Action
{
    /** @param list<GuardianPermission> $permissions */
    public function handle(ParentStudentRelation $relation, array $permissions): ParentStudentRelation
    {
        $relation->forceFill([
            'permissions' => array_values(array_unique(
                array_map(static fn (GuardianPermission $p): string => $p->value, $permissions),
            )),
        ])->save();

        return $relation;
    }
}
