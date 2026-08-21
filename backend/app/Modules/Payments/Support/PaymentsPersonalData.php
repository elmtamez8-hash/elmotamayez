<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\TermsConsent;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Payments's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class PaymentsPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'payments';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['payment_record'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        if (! $subject->mayReceive(GuardianPermission::Payments)) {
            yield from ExportWalk::none(...$this->describe());

            return;
        }

        $userId = $subject->user->getKey();

        /*
        | ⚠️ THE RECEIPT IS REPORTED, NEVER INCLUDED — not the file, not its path,
        | not a link to it. A manual-transfer receipt is an image a HUMAN uploaded,
        | and it routinely carries the name and account number of whoever holds the
        | bank account, who is very often a third party. FR-017 forbids another
        | person's data in this archive, and putting a picture nobody has read into
        | a file we hand over is publishing content of unknown contents.
        |
        | Its EXISTENCE and its DATE are the person's own record and stay.
        */
        yield from ExportWalk::keyed(
            'payment_record',
            Order::query()
                ->withoutWorkspaceScope()
                ->leftJoin('courses', 'courses.id', '=', 'orders.course_id')
                ->leftJoin('media', function (JoinClause $join): void {
                    $join->on('media.model_id', '=', 'orders.id')
                        ->where('media.model_type', '=', Order::class)
                        ->where('media.collection_name', '=', 'receipt');
                })
                ->where('orders.user_id', $userId)
                ->select([
                    'orders.*',
                    'courses.title as course_title',
                    'media.created_at as proof_at',
                ]),
            fn (Order $order): array => [
                'uuid' => $order->uuid,
                'kind' => $order->kind,
                'course_title' => $order->getAttribute('course_title'),
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'provider' => $order->provider,
                'status' => $order->status,
                'rejection_reason' => $order->rejection_reason,
                /*
                | ⚠️ NEITHER KEY CONTAINS THE WORD `receipt`, AND THAT IS NOT
                | squeamishness. `ExportFieldAllowlist` forbids `receipt` as a
                | SUBSTRING — broad on purpose, so that a `receipt_image` added next
                | year is caught by a rule written today rather than by whoever
                | happens to review that diff. The price of a broad rule is that
                | these two legitimate fields, which carry a boolean and a date and
                | no file at all, have to be named for what they hold. They are.
                */
                'has_payment_proof' => $order->getAttribute('proof_at') !== null,
                'payment_proof_at' => ExportWalk::at($order->getAttribute('proof_at')),
                'placed_at' => ExportWalk::at($order->created_at),
                'approved_at' => ExportWalk::at($order->approved_at),
            ],
            column: 'orders.id',
        );

        /*
        | ⚠️ THE CONSENT ROWS TRAVEL UNDER THE SAME CATEGORY, and that is a filing
        | decision worth stating. `terms_consents` has no category of its own in the
        | catalogue, so without this it would reach no archive at all — and it is
        | the one table in this module that is evidence ABOUT the person rather than
        | about a transaction: what they agreed to, when, and from which address.
        | FR-016 asks for everything, and a row nobody declared is exactly the row a
        | complete export is judged by.
        |
        | Both directions are read: `user_id` is who signed, `student_user_id` is who
        | it was signed for, and a guardian consenting for a child writes one row
        | that belongs to both people's records.
        */
        yield from ExportWalk::keyed(
            'payment_record',
            TermsConsent::query()->where(function (Builder $query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('student_user_id', $userId);
            }),
            fn (TermsConsent $consent): array => [
                'uuid' => $consent->uuid,
                'document' => $consent->document,
                'version' => $consent->version,
                'decision' => $consent->decision,
                'categories' => $consent->categories,
                'signed_for_self' => (int) $consent->student_user_id === (int) $consent->user_id,
                // Their own address at their own signature — the evidence that the
                // consent was theirs. Withholding it would remove from a person's
                // copy the one field that proves what they agreed to.
                'ip_address' => $consent->ip_address,
                'consented_at' => ExportWalk::at($consent->consented_at),
            ],
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        // TODO(013-US4): erase or anonymise this module's rows for the subject.
        return 0;
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
