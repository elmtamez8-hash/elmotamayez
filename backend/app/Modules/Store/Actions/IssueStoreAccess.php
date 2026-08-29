<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Actions\MintPlaybackGrant;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Store\Models\StoreOrder;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AccountStanding;
use DomainException;
use RuntimeException;

/**
 * Open a digital purchase (spec 011 · US1 · relocated T020).
 *
 * The SECOND door onto {@see MintPlaybackGrant}. `IssuePlaybackGrant` is the
 * first: it takes a `Lesson` and refuses any asset whose `owner_type` is not
 * `Lesson::class`, so a store product could never ride it — which is why the
 * mint was extracted rather than a second `PlaybackGrant` insert written here.
 *
 * ⚠️ NO ENTITLEMENT CONDITION MAY EVER MOVE INTO THE MINT. Each door asks its
 * own question — an enrolment and a seat there, a paid order here — and a
 * condition that creeps into the shared half is a condition the OTHER door
 * starts enforcing without anybody deciding it should.
 *
 * ⚠️ `AccountStanding`, NOT `Payments\Support\WithholdingReader`. The first is
 * the sanctioned cross-module contract; the second is another module's class and
 * `ContextIsolationTest` fails the build over the import.
 */
class IssueStoreAccess extends Action
{
    public function __construct(
        private readonly MintPlaybackGrant $mint,
        private readonly AccountStanding $standing,
    ) {}

    /**
     * @throws DomainException when the file exists but is not ready
     * @throws RuntimeException when the buyer is not entitled
     */
    public function handle(
        string $purchaseUuid,
        User $buyer,
        AuthSession $session,
        ?string $ipHash = null,
    ): PlaybackGrant {
        /*
        | ⚠️ OWNERSHIP IS RESOLVED HERE, NEVER BY ROUTE-MODEL BINDING.
        | `StoreOrder` carries `BelongsToWorkspace`, which protects nothing at all
        | on a student's path: a student belongs to no workspace, so the context
        | is null and `WorkspaceScope::apply()` adds no condition — an implicit
        | `{purchase}` would resolve any buyer's order by its uuid.
        */
        $purchase = StoreOrder::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $purchaseUuid)
            ->where('buyer_user_id', $buyer->getKey())
            ->first();

        if ($purchase === null) {
            throw new RuntimeException('لا تملك صلاحية لهذا الإجراء.');
        }

        if ($purchase->fulfilled_at === null) {
            // Bought and not yet paid for, as far as this platform knows. A
            // manual transfer takes days; the sentence says which of the two
            // states this is, because «لا تملك صلاحية» about your own purchase
            // sends the buyer to support instead of to the receipt.
            throw new RuntimeException('لم يُعتمَد الدفع لهذا الطلب بعد.');
        }

        if ($purchase->refunded_at !== null) {
            throw new RuntimeException('استُرِدَّ ثمن هذا الطلب.');
        }

        $item = $purchase->item()->withoutWorkspaceScope()->first();
        $asset = $item?->mediaAsset()->withoutWorkspaceScope()->first();

        if ($item === null || $asset === null) {
            throw new RuntimeException('لا يوجد ملف لهذا الطلب.');
        }

        /*
        | ⚠️ THE MONEY AXIS IS ASKED SEPARATELY, exactly as it is for a lesson.
        | Withholding is per COURSE, so it only bites when the product hangs off
        | one — a standalone book has no course to owe against, and inventing a
        | balance for it would withhold a purchase over a debt on the other side
        | of the platform.
        */
        if ($item->course_id !== null && $this->standing->isWithheld($buyer, (int) $item->course_id)) {
            throw new RuntimeException('هذا الملف موقوف حتى سداد رصيد هذا الكورس.');
        }

        /*
        | ⚠️ THE MINT RUNS FIRST, AND THE ORDER OF THESE TWO LINES IS THE WHOLE
        | POINT. `MintPlaybackGrant` throws when the file is not playable yet — a
        | book still transcoding — and a stamp written before it would close the
        | refund window on a purchase that NEVER OPENED. The buyer reads
        | «قيد التجهيز», taps again tomorrow, and is then told
        | «فُتِح هذا الملف» about a file nobody has read. Nothing sweeps that
        | back: the window is a clock, and it had already run out.
        |
        | ⚠️ AND IT IS STILL CLAIMED, NOT ASSIGNED. Two grants minted at the same
        | instant would both read the column as null and both write, moving the
        | stamp forward and handing back a window that had already closed. One
        | conditional UPDATE, the seat idiom; never `lockForUpdate()`, a no-op on
        | SQLite.
        */
        $grant = $this->mint->handle($asset, $buyer, $session, $ipHash);

        StoreOrder::query()
            ->withoutWorkspaceScope()
            ->whereKey($purchase->getKey())
            ->whereNull('first_accessed_at')
            ->update(['first_accessed_at' => now()]);

        return $grant;
    }
}
