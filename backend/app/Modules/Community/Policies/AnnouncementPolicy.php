<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;

/**
 * Who may address a teacher's whole class at once (FR-042).
 *
 * ⚠️ ITS OWN PERMISSION, NOT `CHAT_REPLY` REUSED — and that is a departure from
 * `GradingSchemePolicy` two files away, which reuses one deliberately. The test
 * is whether the two powers are over the same people in the same way. Weighting
 * grades and writing an assessment are both the teacher's judgement of one
 * student's term, so one name serves. Answering a question in a thread the
 * student opened, and sending three hundred families a message they cannot reply
 * to at all (FR-045), are not: the first is invited and private, the second goes
 * out in the teacher's name to people who never asked for it. An assistant
 * trusted to answer questions is not thereby trusted to announce a change of fees.
 *
 * It therefore costs a fifth backfill migration, which is the price and not an
 * oversight — a new constant reaches nobody who already exists, because
 * `SeedDefaultRoles` runs once at workspace creation.
 */
class AnnouncementPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permissions::ANNOUNCEMENTS_MANAGE);
    }
}
