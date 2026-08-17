<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Media\Enums\PlaybackFormat;
use App\Modules\Media\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BunnyFixtures;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

/*
| SC-002 · SC-003 — THE GRANT CUTS THE BYTES, NOT THE INDEX.
|
| ⚠️ AND THAT DISTINCTION IS THE WHOLE OF THIS FILE. A signed HLS manifest whose
| SEGMENTS are unsigned is protection that looks complete and is not: whoever holds
| one URL holds the whole video, with no grant, no watermark and no device limit
| behind it. The documentation is explicit that path tokens are what protect the
| `.ts` files along with the playlist (research §R5).
|
| A test that asks for the manifest and stops there passes over exactly that defect,
| which is why what is asserted below is the shape of the token itself.
*/

const BUNNY_GUID = 'aa11bb22-cc33-dd44-ee55-ff6677889900';

beforeEach(function (): void {
    config(BunnyFixtures::config());

    // Nothing here may reach the network: manifest() signs locally, and a call on
    // the playback path would turn a provider slowdown into zero viewing rather
    // than degraded viewing (FR-013).
    Http::preventStrayRequests();

    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->lesson = $this->lessonWithVideo($this->workspace);

    // The same lesson, stored at the commercial provider instead of on our disk.
    MediaAsset::query()->withoutWorkspaceScope()
        ->where('owner_id', $this->lesson->getKey())
        ->where('owner_type', Lesson::class)
        ->update(['provider' => 'bunny', 'provider_asset_id' => BUNNY_GUID]);

    [$this->student, $this->session] = $this->enrolledViewer($this->workspace, $this->lesson);
});

function issueGrant(Lesson $lesson): array
{
    return test()->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk()->json();
}

/** The Location a redirect provider answers our own stream route with. */
function signedUrl(string $grant): string
{
    return (string) test()->get("/api/v1/playback/{$grant}/stream")
        ->assertRedirect()
        ->headers->get('Location');
}

/**
 * The token parameters, read out of the PATH — which is where they live for HLS.
 *
 * The first path segment is `bcdn_token=…&token_path=…&expires=…`; the real object
 * path follows the next `/`, and `token_path` cannot be confused with it because it
 * travels rawurlencoded (`%2F`).
 *
 * @return array<string, string>
 */
function tokenParams(string $url): array
{
    $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

    parse_str((string) strstr($path, '/', true), $params);

    /** @var array<string, string> $params */
    return $params;
}

it('signs the whole video directory, not the playlist file alone', function (): void {
    $params = tokenParams(signedUrl(issueGrant($this->lesson)['grant']));

    expect($params)->toHaveKeys(['bcdn_token', 'expires', 'token_path']);

    // ⚠️ THE DIRECTORY, WITH ITS TRAILING SLASH. `/{guid}/playlist.m3u8` here would
    // be a token that covers the index and nothing under it — every segment served
    // to anyone who asks.
    expect($params['token_path'])->toBe('/'.BUNNY_GUID.'/');

    // The advanced scheme, which is the only one with a path allowance at all. The
    // basic scheme is an MD5 with no `token_path`, so it cannot protect HLS.
    expect($params['bcdn_token'])->toStartWith('HS256-');
});

/*
| ⚠️ THE PLACEMENT IS HALF OF THE PROTECTION, AND IT HAD NO GUARD.
|
| `?token=…` and `/bcdn_token=…` are two documented forms, not two spellings of one.
| Per RFC 3986 a relative reference inherits the base query only when its own path is
| EMPTY — and Bunny's `playlist.m3u8` is a master playlist referencing
| `720p/video.m3u8`, a non-empty path. So in the query form the index loads signed and
| every rendition playlist and every `.ts` under it leaves UNSIGNED, which is exactly
| the state the expiry test below asserts is refused: the guard passed while no
| student could watch anything.
|
| The signature itself is identical in both forms, so nothing but this assertion
| distinguishes them — and the arithmetic is pinned separately in BunnyTokenVectorTest.
*/
it('carries the token in the path, so relative segment requests inherit it', function (): void {
    $url = signedUrl(issueGrant($this->lesson)['grant']);

    expect((string) parse_url($url, PHP_URL_QUERY))->toBe('')
        ->and($url)->toContain('/bcdn_token=HS256-')
        // And the object path still comes last, after the parameters.
        ->and($url)->toEndWith('/'.BUNNY_GUID.'/playlist.m3u8');
});

it('points at the provider network and declares the format the player must handle', function (): void {
    $payload = issueGrant($this->lesson);

    expect($payload['format'])->toBe(PlaybackFormat::Hls->value);

    $url = signedUrl($payload['grant']);

    expect($url)->toStartWith('https://'.BunnyFixtures::PULL_ZONE.'.b-cdn.net/')
        ->and($url)->toContain('/playlist.m3u8');
});

/*
| SC-003 — the URL dies with the grant.
|
| ⚠️ MEASURED FROM `now()` TO `expires`, NEVER THE OTHER WAY. On a future date
| Carbon returns a NEGATIVE difference, so `expiresAt->diffInMinutes(now()) < N`
| is true for any lifetime whatsoever — which is how 017 shipped a green assertion
| over a ticket good for six hours.
*/
it('expires the signature with the grant and not after it', function (): void {
    $payload = issueGrant($this->lesson);

    $params = tokenParams(signedUrl($payload['grant']));

    $grantExpiry = CarbonImmutable::parse($payload['expires_at']);

    expect((int) $params['expires'])->toBe($grantExpiry->getTimestamp());

    // And the lifetime is the configured TTL, not a literal: the TTL is a
    // platform_settings row an operator tunes, and a hard-coded ceiling fails the
    // gate over a legitimate config change.
    expect(now()->diffInSeconds($grantExpiry, absolute: true))
        ->toBeLessThanOrEqual((int) config('media.grant_ttl_seconds') + 5);
});

/*
| ⚠️ THE RELOAD CADENCE, WHICH IS WHAT MAKES A SHORT GRANT MEAN ANYTHING HERE.
|
| A redirect provider is authorised ONCE: the browser fetches the manifest, then talks
| to the CDN directly with a URL signed for the grant's expiry as it stood at that
| moment. `RenewPlaybackGrant` extends the ROW and cannot reach that URL — it returns a
| byte-identical `manifest_url`, so a client that merely renews watches the lesson die
| at the first token expiry and every renewal after that is decoration. Coming back
| through `stream()` is the only thing that mints a fresh token, so the server states
| the cadence rather than leaving the player to guess a TTL it was never told.
|
| The null case is not a detail — it is what makes the field mean something. A provider
| that serves its own bytes is re-authorised on every range request, and reloading
| there would be a re-buffer bought for nothing.
*/
it('tells a redirect player how often to come back for a fresh signature', function (): void {
    $payload = issueGrant($this->lesson);

    // Two thirds of the TTL: a reload that fails still has a live token to retry
    // under. Derived, never a literal — the TTL is an operator's row.
    expect($payload['reload_after_seconds'])
        ->toBe(intdiv((int) config('media.grant_ttl_seconds') * 2, 3))
        // And it must land inside the token's life, or it is not a renewal at all.
        ->toBeLessThan((int) config('media.grant_ttl_seconds'));
});

/*
| SC-002 — what is asked for AFTER expiry is the thing that fetches bytes.
|
| A player re-requests segments continuously, so the door that matters is the one
| the NEXT segment goes through. Here that door is ours: no redirect is issued at
| all, so there is no signed URL to fetch anything with.
*/
it('refuses to hand out a signature once the grant has expired', function (): void {
    $grant = issueGrant($this->lesson)['grant'];

    // Works now.
    $this->get("/api/v1/playback/{$grant}/stream")->assertRedirect();

    $this->travel((int) config('media.grant_ttl_seconds') + 60)->seconds();

    // ⚠️ NOT "the index is refused". This route is what mints the credential that
    // fetches every byte, and after expiry it mints nothing.
    $this->get("/api/v1/playback/{$grant}/stream")->assertForbidden();
});

// FR-014 — the decision is at our gate, never at the provider's. An unentitled
// viewer must not receive a signature to be refused later by someone else.
it('signs nothing at all for a viewer with no entitlement', function (): void {
    // A signed-in student with no enrolment in this course — not a workspace
    // member, who legitimately may see their own teacher's material.
    $stranger = User::factory()->create(['platform_role' => PlatformRole::Student]);
    Sanctum::actingAs($stranger);
    $this->asGuest();
    $this->sessionFor($stranger)
        ->forceFill(['token_id' => $stranger->currentAccessToken()->getKey()])
        ->save();

    $response = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertForbidden();

    // Nothing resembling a signature travelled, and neither did the provider's id
    // for the file.
    expect($response->getContent())->not->toContain('HS256-')
        ->and($response->getContent())->not->toContain(BUNNY_GUID);
});

/*
| ⚠️ FR-011 — THE PAYLOAD CARRIES OUR ROUTE, AND THE CDN URL NEVER APPEARS IN IT.
|
| This one nearly shipped. `PlaybackGrantResource` emitted `$manifest->url`, which
| was harmless while the only provider pointed that back at us — and becomes the
| provider's hostname plus its asset id the moment one does not. The signed URL
| exists only as a redirect Location.
*/
it('keeps the provider network and the asset id out of the payload', function (): void {
    $response = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertOk();

    $body = $response->getContent();

    expect($response->json('manifest_url'))->toContain('/api/v1/playback/')
        ->and($body)->not->toContain('b-cdn.net')
        ->and($body)->not->toContain(BUNNY_GUID)
        ->and($body)->not->toContain('HS256-')
        // The identifier itself is never exposed either (004 FR-019).
        ->and($body)->not->toContain('bunny');
});

// FR-015 · FR-018 — 004's protections are untouched by the change of who serves
// the bytes. The captions still come through the grant, because a <track> sends no
// Authorization header and a permanent caption URL is the lesson's whole script.
it('still serves captions through the grant, not from the provider', function (): void {
    $payload = issueGrant($this->lesson);

    foreach ($payload['captions'] as $caption) {
        expect($caption['url'])->toContain("/api/v1/playback/{$payload['grant']}/captions/");
    }

    // And the watermark is still built on the server for this viewer.
    expect($payload['watermark'])->not->toBeEmpty()
        ->and($payload['renew_after_seconds'])->toBeGreaterThan(0);
});
