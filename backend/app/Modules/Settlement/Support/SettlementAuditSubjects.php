<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeacherPayout;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Shared\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

/**
 * What the settlement audit trail is allowed to be about.
 *
 * FR-034 asks that neither context see the other's audit, and this is where that
 * is decided — as the SHAPE of the query rather than as a filter applied to a
 * wider one. `activity_log` is a single shared table: an order approval, a
 * certificate, a workspace rename and a teacher's payout all land in it. A reader
 * that fetched the table and then removed the billing rows would be one forgotten
 * `else` away from showing an auditor of teacher pay what a student paid.
 *
 * So the settlement audit endpoint never asks for the table. It asks for these
 * six subject types, and there is no branch in which it asks for more.
 *
 * The inverse is equally deliberate: `Payments\Actions\ApproveOrder` logs to the
 * same table and this list does not contain its subject, so the billing side is
 * invisible here by construction, not by omission.
 */
final class SettlementAuditSubjects
{
    /**
     * Model class → the slug the payload names it by.
     *
     * A slug rather than the class name, because a class name on the wire is a
     * map of the codebase handed to whoever holds a token — and it changes under
     * a rename, which a stored audit entry must not.
     *
     * @var array<class-string, string>
     */
    public const MAP = [
        SettlementPeriod::class => 'period',
        TeacherPayout::class => 'payout',
        TeachingUnit::class => 'unit',
        LedgerEntry::class => 'ledger_entry',
        SettlementRate::class => 'rate',
        RateChangeRequest::class => 'rate_request',
    ];

    /** @return list<class-string> */
    public static function types(): array
    {
        return array_keys(self::MAP);
    }

    public static function slugFor(?string $class): ?string
    {
        return $class === null ? null : (self::MAP[$class] ?? null);
    }

    /**
     * The audit trail itself — one spelling, read by the API and by /admin.
     *
     * ⚠️ THE `whereIn` IS UNCONDITIONAL, and a screen narrowing it further (a
     * filter on one subject type) narrows INSIDE these six, never around them.
     *
     * ⚠️ AND THE SUBJECT IS LOADED WITHOUT THE WORKSPACE SCOPE. Five of the six
     * subject models carry `BelongsToWorkspace`, and the reader is a platform
     * officer whose context falls back to their own `last_workspace_id` — so a
     * bare `->with('subject')` answered `subject_uuid: null` for every act in
     * any other workspace, and the audit read as «the subject is gone» about
     * rows that were standing. PLURAL `withoutGlobalScopes([...])` on purpose:
     * `MorphTo` buffers that call and replays it on each type's query, and it
     * does NOT buffer the singular `withoutGlobalScope()`.
     *
     * @return Builder<Activity>
     */
    public static function entries(): Builder
    {
        return Activity::query()
            ->whereIn('subject_type', self::types())
            ->with([
                'subject' => fn (Relation $subject) => $subject->withoutGlobalScopes([WorkspaceScope::class]),
                'causer',
            ])
            ->latest('id');
    }
}
