<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Data\AddChildData;
use App\Modules\Identity\Models\ParentChildLink;
use App\Modules\Identity\Support\PlatformRole;
use App\Shared\Actions\Action;
use DomainException;

class AddChild extends Action
{
    public function handle(User $parent, AddChildData $data): ParentChildLink
    {
        $child = $data->childUuid === null ? null : $this->resolveChild($data->childUuid);

        if ($child !== null && $this->alreadyLinked($parent, $child)) {
            throw new DomainException('هذا الطالب مرتبط بحسابك بالفعل.');
        }

        return ParentChildLink::query()->create([
            'parent_id' => $parent->getKey(),
            'child_id' => $child?->getKey(),
            'child_name' => $data->name,
            'child_age' => $data->age,
            'child_grade_level_slug' => $data->gradeLevelSlug,
        ]);
    }

    /**
     * Attaching an existing account is the one place a parent can name someone
     * else's record, so the rules are strict: it must be a student account, and
     * "not found" and "not a student" answer the same way — otherwise the endpoint
     * confirms which email addresses exist on the platform.
     */
    private function resolveChild(string $uuid): User
    {
        $child = User::query()
            ->where('uuid', $uuid)
            ->where('platform_role', PlatformRole::Student)
            ->first();

        if ($child === null) {
            throw new DomainException('لم نجد حساب طالب بهذا المعرّف.');
        }

        return $child;
    }

    private function alreadyLinked(User $parent, User $child): bool
    {
        return ParentChildLink::query()
            ->where('parent_id', $parent->getKey())
            ->where('child_id', $child->getKey())
            ->exists();
    }
}
