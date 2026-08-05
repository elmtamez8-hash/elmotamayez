<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * A student has at most one parent and any number of guardians.
 *
 * The distinction is not cosmetic: the parent is the account the platform treats
 * as financially and legally answerable for the student, which is why LinkGuardian
 * refuses a second active one (FR-019). A guardian is anyone else the family
 * chooses to keep informed — a grandparent, an older sibling, a tutor.
 */
enum RelationType: string
{
    case Parent = 'parent';
    case Guardian = 'guardian';

    public function label(): string
    {
        return match ($this) {
            self::Parent => 'وليّ أمر',
            self::Guardian => 'وصيّ',
        };
    }
}
