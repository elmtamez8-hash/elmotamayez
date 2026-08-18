<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

/**
 * What a media payload may say, and what it may never say.
 *
 * ⚠️ MEDIA WAS THE ONE MODULE WITHOUT ONE. `PublicFieldAllowlist`,
 * `StudentBalanceAllowlist`, `TeacherFieldAllowlist`, `AssessmentFieldAllowlist`
 * and `PaymentFieldAllowlist` have all existed for phases — and the module whose
 * entire reason for being is that a video must not become a shareable link was
 * guarded by four `not->toContain` needles in one playback test. Four needles
 * catch the four things somebody thought of.
 *
 * FR-019 is what this enforces: no vendor name, no library id, no
 * `provider_asset_id`, no storage path, no signing key. Any one of them turns a
 * grant-protected lesson into a URL somebody can keep — and none of them is
 * something a screen needs. The teacher's own view is the widest one there is,
 * and it still only answers "is it ready, and if not, why".
 *
 * A field added below is a decision to send it to a browser. Treat it as one.
 */
final class MediaFieldAllowlist
{
    /**
     * Keys that must NEVER appear in a media payload, at any depth.
     *
     * ⚠️ THE HOSTNAME IS IN HERE FOR A REASON THAT ALREADY BIT ONCE.
     * `PlaybackGrantResource` sends OUR route as `manifest_url`, never the
     * provider's — for a redirect provider that url is the CDN hostname plus
     * `provider_asset_id`, and it travelled for free while the only provider
     * pointed its manifest back at us. The signed url exists solely as the `302`
     * Location from `stream()`.
     *
     * @var list<string>
     */
    public const FORBIDDEN = [
        'provider',
        'provider_asset_id',
        'library_id',
        'access_key',
        'security_key',
        'pull_zone',
        'storage_path',
        'disk',
        // The join key. Harmless-looking, and it is `{prefix}:{asset_uuid}` — the
        // one string that names a provider-side object from ours.
        'title_prefix',
        'workspace_id',
        'id',
    ];

    /**
     * Everything the OWNER's view of their own asset may carry.
     *
     * The widest media payload in the product: a teacher looking at a file they
     * uploaded. Every other one — the student's, the public one — is narrower by
     * construction, so a key that is not here belongs in no payload at all.
     *
     * @var list<string>
     */
    public const OWNER = [
        'uuid',
        'status',
        'status_label',
        'kind',
        'kind_label',
        'role',
        'is_downloadable',
        'original_filename',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        // ⚠️ THE ONE FIELD THAT CARRIES FREE TEXT FROM THE PROVIDER, which is why
        // no `getMessage()` may ever reach it: a Guzzle message quotes the full
        // request URL — vendor host, library id and video id, all three forbidden
        // above. The containment test scans SOURCE files, so a string assembled at
        // runtime walks straight past it.
        'failure_reason',
        'ready_at',
        'created_at',
    ];
}
