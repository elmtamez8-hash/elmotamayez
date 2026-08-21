<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Events\MediaAssetsExpired;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackGrant;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Throwable;

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
    public function __construct(private readonly MediaProviderResolver $providers) {}

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
            yield from ExportWalk::none(...$this->describe());

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
        if ($mode !== ErasureMode::Delete) {
            return 0;
        }

        /*
        | A playback grant is a short-lived PERMISSION, not a record of anything the
        | platform is obliged to keep — and what it holds is a viewing log: which
        | recordings this person opened, when, and from which address hash. The
        | recordings themselves belong to the sessions and to everybody who booked a
        | seat in them; nothing here touches an asset.
        */
        return PlaybackGrant::query()
            ->where('user_id', $subject->user->getKey())
            ->limit($limit)
            ->delete();
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     *
     * @param  list<int>  $exemptUserIds  subjects under a live hold — their rows stay.
     */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        if ($category !== 'class_recording' || $mode !== ExpiryBehaviour::Archive) {
            return 0;
        }

        /*
        | ⚠️ `owner_type` IS THE ONLY THING SEPARATING A CLASS RECORDING FROM A
        | TEACHER'S OWN LESSON VIDEO, and getting it wrong deletes the product. A
        | video a teacher uploaded belongs to `authored_content`, whose catalogue
        | row carries a NULL retention on purpose — sweep `media_assets` broadly and
        | every course video on the platform is destroyed on its second birthday.
        |
        | The class name is imported for a morph-type comparison and nothing else:
        | the string is already stored in THIS module's own column, and the two
        | alternatives are worse — another module's table name in a join (the
        | coupling `ContextIsolationTest` fails the build over) or a match on the
        | filename prefix, which is a convention no constraint enforces.
        |
        | ⚠️ AND `$exemptUserIds` IS DELIBERATELY NOT APPLIED HERE. A hold is about
        | ONE PERSON; a recording is a room full of them. Preserving every class a
        | held student ever sat would freeze dozens of other people's data on one
        | person's order, and there is no column here that names anybody — the link
        | runs through `attendances`, which belongs to another module. What FR-030
        | protects is the held person's own rows, and those are held by the six
        | categories that do carry a user column.
        */
        $assets = MediaAsset::query()
            ->withoutWorkspaceScope()
            ->whereNull('archived_at')
            ->where('owner_type', ClassSession::class)
            ->where('created_at', '<', $before->toDateTimeString())
            ->limit($limit)
            ->get();

        if ($assets->isEmpty()) {
            return 0;
        }

        $expired = [];

        foreach ($assets as $asset) {
            /*
            | ⚠️ THE PROVIDER FIRST, THEN THE ROW. The other order orphans a video
            | that is billed monthly with nothing left in our database naming it —
            | and a `404` from the provider is FREE on purpose, so a sweep that
            | crashed after deleting the file costs nothing on its next pass.
            */
            try {
                $this->providers->for($asset)->delete($asset);
            } catch (Throwable $e) {
                /*
                | One asset's provider refusing must not end the night's sweep for
                | every other category. The row keeps its null `archived_at`, so the
                | next pass tries again — and the run log carries the finding.
                */
                report($e);

                continue;
            }

            $asset->forceFill([
                'archived_at' => now(),
                // The id named a file that no longer exists.
                'provider_asset_id' => null,
                /*
                | ⚠️ `Failed` RATHER THAN A NEW CHECK IN EVERY PLAYBACK GUARD. The
                | bytes are gone, so a grant issued over this row would answer 404
                | to a student with no explanation anywhere; `Failed` is the state
                | those guards already refuse, and `ReconcileAssetStatus` sweeps
                | `Processing` alone so nothing resurrects it.
                */
                'status' => MediaAssetStatus::Failed,
                'failure_reason' => 'انقضت مدّة الاحتفاظ بالتسجيل.',
            ])->save();

            $expired[] = [
                'id' => (int) $asset->getKey(),
                'owner_type' => (string) $asset->owner_type,
                'owner_id' => (int) $asset->owner_id,
            ];
        }

        if ($expired !== []) {
            /*
            | ⚠️ ONE EVENT FOR THE WHOLE BATCH — FR-031ب's resync happens once per
            | COURSE, not once per recording. Fifty recordings of one course would
            | otherwise run fifty full progress resyncs over the same enrolments,
            | which is what actually threatens this job's timeout.
            */
            MediaAssetsExpired::dispatch($expired);
        }

        return count($expired);
    }
}
