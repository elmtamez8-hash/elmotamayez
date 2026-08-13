<?php

declare(strict_types=1);

namespace App\Shared\Models;

use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit entry, made unamendable (FR-027).
 *
 * ⚠️ SPATIE DOES NOT ENFORCE THIS. `activity_log` is an ordinary table with an
 * ordinary model and `$guarded = []`; nothing in the package refuses an update or
 * a delete. An audit trail that can be edited answers a different question from
 * the one an auditor is asking — and the whole value of the trail is that its
 * answer cannot have been arranged afterwards.
 *
 * The shape is `LedgerEntry::booted()`, shipped in 014 for the same reason and
 * for the same kind of row.
 *
 * ⚠️ AND THIS IS A PRODUCT-WIDE CHANGE, NOT A BILLING ONE. Registering it as
 * `activity_model` replaces the model behind every `activity()` call in the
 * codebase — twenty-three Actions across seven modules, including spec 014's
 * settlement audit. That breadth is exactly why the shared `LogsActivity` trait
 * was left untouched: the guard belongs on the row, where it holds for every
 * writer, rather than in a helper that a future writer can decline to use.
 *
 * ⚠️ AND THE GUARD IS ON THE MODEL, SO IT SEES ONLY MODEL WRITES.
 * `DB::table('activity_log')->delete()` retrieves nothing and boots nothing, and
 * would go straight through. That is not a hole this class can close — it is the
 * same limit `LedgerEntry` documents — and it is why the guard is a statement of
 * intent enforced at the one door the application actually uses, with the
 * database's own permissions the answer to anyone reaching past it.
 */
class ActivityEntry extends Activity
{
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('قيد التدقيق لا يُعدَّل. السجلّ يوثّق ما حدث، ولا يُعاد كتابته.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('قيد التدقيق لا يُحذف. السجلّ يوثّق ما حدث، ولا يُعاد كتابته.');
        });
    }
}
