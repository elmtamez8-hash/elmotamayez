<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Models\PlaybackGrant;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;

/**
 * Media's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class MediaPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'media';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['class_recording'];
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
        /*
        | ⚠️ GATED ON `Results`, AND THE OBVIOUS MAPPING IS THE WRONG ONE. What this
        | module holds about a person is a VIEWING LOG — which recordings they
        | opened, when, and how long the grant was renewed for. The tempting gate is
        | `Attendance`, because `attendances.recording_watched_at` already treats
        | watching a recording as being present; and it is exactly the mapping
        | `GuardianScopeTest` refuses, because a guardian granted presence alone
        | would then learn which lessons their child studied and for how long.
        | That is academic detail, so it answers to the academic gate.
        |
        | No `GuardianPermission` names a viewing log. Inventing a near-enough one
        | is how a guardian granted one thing receives another, so the choice is
        | written here rather than left to be re-derived.
        */
        if (! $subject->mayReceive(GuardianPermission::Results)) {
            return;
        }

        /*
        | ⚠️ `provider_asset_id` IS ABSENT AND MUST STAY ABSENT. FR-011 keeps the
        | provider's own identifier out of every payload, and an export is the
        | payload with the longest life of any in the product.
        | {@see \App\Modules\Compliance\Support\ExportFieldAllowlist} fails the build
        | over it, at any depth.
        */
        yield from ExportWalk::keyed(
            'class_recording',
            // No `withoutWorkspaceScope()`: `PlaybackGrant` deliberately does not
            // use `BelongsToWorkspace` — it is issued and consumed with no workspace
            // context — so there is no scope here to bypass.
            PlaybackGrant::query()
                ->leftJoin('media_assets', 'media_assets.id', '=', 'playback_grants.media_asset_id')
                ->where('playback_grants.user_id', $subject->user->getKey())
                ->select([
                    'playback_grants.*',
                    'media_assets.original_filename as asset_filename',
                    'media_assets.kind as asset_kind',
                ]),
            fn (PlaybackGrant $grant): array => [
                'uuid' => $grant->uuid,
                'asset_filename' => $grant->getAttribute('asset_filename'),
                'asset_kind' => $grant->getAttribute('asset_kind'),
                'issued_at' => ExportWalk::at($grant->created_at),
                'expires_at' => ExportWalk::at($grant->expires_at),
                'revoked_at' => ExportWalk::at($grant->revoked_at),
                'renewed_count' => $grant->renewed_count,
                'last_seen_at' => ExportWalk::at($grant->last_seen_at),
            ],
            column: 'playback_grants.id',
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
