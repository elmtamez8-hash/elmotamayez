<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * Platform-level role, orthogonal to workspace roles.
 *
 * spatie/permission runs in team mode, so its roles only mean anything inside a
 * workspace. Marketplace accounts belong to no workspace, which is exactly why
 * this lives on the users table instead — see R3.
 *
 * `null` means the account came through the academy-signup path (FR-011).
 */
enum PlatformRole: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Parent = 'parent';
}
