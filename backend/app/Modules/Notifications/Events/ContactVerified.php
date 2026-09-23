<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A person has just proved they own a contact detail.
 *
 * ⚠️ THE ONLY MOMENT A CONTACT BECOMES EVIDENCE. Before `verified_at` a number is
 * a string somebody typed; after it, it names whoever proved it — which is why
 * `GuardianContactResolver` reads verified rows alone, and why a module that wants
 * to act on "this number belongs to this account" listens here rather than
 * reading `users.phone`.
 *
 * Carries ids and the value, never the model: a listener in another module reads
 * what it needs through its own queries, and a queued listener serialises this.
 */
class ContactVerified
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly string $contactValue,
    ) {}
}
