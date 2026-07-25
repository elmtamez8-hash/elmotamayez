<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * Role name constants. These are seeded into spatie/permission's roles table.
 * The super-admin role is global (team_id = null); all others are workspace-scoped.
 */
final class Roles
{
    public const SUPER_ADMIN = 'super-admin';

    public const TENANT_OWNER = 'tenant-owner';

    public const TEACHER = 'teacher';

    public const ASSISTANT_TEACHER = 'assistant-teacher';

    public const STUDENT = 'student';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SUPER_ADMIN,
            self::TENANT_OWNER,
            self::TEACHER,
            self::ASSISTANT_TEACHER,
            self::STUDENT,
        ];
    }

    /** @return list<string> Workspace-scoped roles (excludes super-admin). */
    public static function workspaceRoles(): array
    {
        return [
            self::TENANT_OWNER,
            self::TEACHER,
            self::ASSISTANT_TEACHER,
            self::STUDENT,
        ];
    }
}
