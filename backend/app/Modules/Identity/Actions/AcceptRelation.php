<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use DomainException;

/**
 * The party who did not ask settles a pending guardian link (spec 030 · FR-001).
 *
 * This is the one thing the product could not do. `LinkGuardian` writes `pending`
 * for a child who holds an account, `RegisterStudent::inviteGuardian` writes it
 * from the other side, and until now nothing in `app/` could move either to
 * `active` — so every guardian-gated feature, including the purchase shipped in
 * 029, was dead for any real family.
 */
class AcceptRelation extends Action
{
    public function __construct(private readonly DispatchNotification $notifications) {}

    public function handle(User $actor, ParentStudentRelation $relation): ParentStudentRelation
    {
        /*
        | ⚠️ EVERY REFUSAL STANDS ABOVE THE CLAIM.
        |
        | `GradeAttempt` records the mirror of this: a refusal raised after a
        | conditional UPDATE strands the row in the very state the refusal exists
        | to prevent. And this guard is asked here as well as in the policy
        | because `AppServiceProvider`'s `Gate::before` waves a super admin past
        | every policy method — SC-003 ("nobody but the other party may activate a
        | link, the administration included") is only true because of this line.
        |
        | `decidableBy()` is the one spelling: the policy asks it, the Resource's
        | `can_decide` asks it, and so does this.
        */
        if (! $relation->wasAskedOf($actor)) {
            throw new DomainException('لا يمكنك البتّ في هذا الطلب.');
        }

        if ($relation->status === RelationStatus::Revoked->value) {
            throw new DomainException('انتهى هذا الطلب.');
        }

        if (! $relation->decidableBy($actor)) {
            // Already accepted. FR-005 says nothing changes — not that anyone
            // complains — and `accepted_at` below is never re-stamped.
            return $relation;
        }

        /*
        | The claim: one conditional UPDATE, which is both the check and the write.
        |
        | It makes FR-005 true BY CONSTRUCTION rather than by a branch somebody has
        | to remember — two taps arriving together, one wins, `accepted_at` moves
        | once. Same idiom as `captured_order_id` and `StructureVersion::claim()`.
        |
        | ⚠️ THROUGH THE QUERY BUILDER, NEVER `forceFill()->save()`. `save()`
        | writes the model's whole dirty set from a snapshot read earlier in the
        | request, so it would silently overwrite a concurrent write to another
        | column. And never `lockForUpdate()`: `SQLiteGrammar::compileLock()`
        | returns an empty string, so a test written around a lock passes locally
        | and proves nothing about the MySQL this ships to.
        */
        $claimed = ParentStudentRelation::query()
            ->whereKey($relation->getKey())
            ->where('status', RelationStatus::Pending->value)
            ->update([
                'status' => RelationStatus::Active->value,
                'accepted_at' => now(),
                'updated_at' => now(),
            ]);

        $relation->refresh();

        /*
        | ⚠️ ZERO ROWS DOES NOT MEAN "ALREADY ACCEPTED".
        |
        | `RevokeRelation` is a read-then-write with no status predicate, so a
        | revoke racing this claim is perfectly ordinary — and then zero rows means
        | the link was CUT, not accepted. Branching on the row count alone would
        | tell a student who had just refused the link that the guardian's
        | acceptance succeeded. The branch is on the refreshed status.
        */
        if ($claimed === 0) {
            if ($relation->status === RelationStatus::Revoked->value) {
                throw new DomainException('انتهى هذا الطلب.');
            }

            return $relation;
        }

        $this->notifyRequester($actor, $relation);

        return $relation;
    }

    /**
     * The requester learns the answer (FR-006).
     *
     * Dispatched INLINE rather than through an event, which is Identity's own
     * shape: the module calls `DispatchNotification` directly in five places and
     * fires no event that Notifications listens to. It matters more than
     * consistency in the abstract — `RegisterStudent` notifies about THE SAME ROW
     * from the other direction with an inline call, so an event here would give
     * one row's lifecycle two delivery mechanisms inside one module.
     */
    private function notifyRequester(User $actor, ParentStudentRelation $relation): void
    {
        $requester = User::query()->find($relation->requested_by_user_id);

        if ($requester === null) {
            return;
        }

        $this->notifications->handle(new NotificationRequest(
            recipient: $requester,
            type: NotificationType::GuardianLinkDecided,
            variables: ['name' => $actor->name],
            // ⚠️ Without this the row renders as a plain <div>: `NotificationRow`
            // only wraps a link when `action_url` is set, so a notification about
            // a decision would lead nowhere at all.
            actionUrl: '/family',
            subject: $relation->student_user_id === null ? null : $relation->student,
        ));
    }
}
