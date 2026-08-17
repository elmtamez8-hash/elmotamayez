<?php

declare(strict_types=1);

use App\Modules\Media\Providers\BunnyMediaProvider;

/*
| SC-002 — THE SIGNATURE IS MEASURED AGAINST THE VENDOR'S OWN PUBLISHED VECTOR.
|
| ⚠️ AND A SHAPE ASSERTION IS WHY THIS FILE EXISTS. `BunnyPlaybackTest` checked that
| the token starts with `HS256-` and that `token_path` is present with a trailing
| slash — both of which a WRONG signature also satisfies. So the suite was green over
| a token that could not validate, and the first thing a real account would have
| answered is 403 on every video, with nothing in our logs: `stream()` returns a
| clean 302 and the refusal happens between the browser and the CDN.
|
| The defect the vector catches: `token_path` is itself one of the SIGNED parameters
| (the exclusion list is `token` and `expires` alone), so the hashed message is
| `signature_path + expires + "token_path=" + signature_path`. The adapter used to
| hash `signature_path + expires`, on the reasoning — written in its own docblock —
| that "we add no query parameters". We add exactly one, and it is signed.
|
| ⚠️ WHY A VECTOR AND NOT A SECOND SHAPE CHECK: a signature has exactly one correct
| value and no observable structure. Anything short of comparing against a known
| answer is a test of the code against itself. This needs no account and no network —
| it is `hash_hmac` arithmetic, which is the whole point.
|
| Source: BunnyWay/BunnyCDN.TokenAuthentication, `php/url_signing.php` — the
| reference implementation bunny.net's advanced-token documentation points at:
|
|     if ($path_allowed !== '') { $parameters['token_path'] = $path_allowed; }
|     ksort($parameters);
|     $signature_path = $path_allowed !== '' ? $path_allowed : $url_path;
|     $signing_data = implode('&', $signing_parts);      // "token_path=/abc"
|     $message = $signature_path . $expires . $ip_bytes . $signing_data;
|     $digest = hash_hmac('sha256', $message, $security_key, true);
*/

/** The `path_only` case from the reference implementation's published vectors. */
const VECTOR_KEY = 'SecurityKey';
const VECTOR_PATH = '/abc';
const VECTOR_EXPIRES = 1598024587;
const VECTOR_TOKEN = 'HS256-uVZvT3SbEoVKYJyDJgbcsDmSFf73cv-uNUVaJiKWpbQ';

/** The adapter's own signing, reached through the class rather than reimplemented. */
function tokenFor(string $path, int $expires, string $key): string
{
    config(['media.bunny.security_key' => $key]);

    $sign = (new ReflectionMethod(BunnyMediaProvider::class, 'token'))
        ->getClosure(app(BunnyMediaProvider::class));

    return $sign($path, $expires);
}

it('reproduces the vendor published vector byte for byte', function (): void {
    expect(tokenFor(VECTOR_PATH, VECTOR_EXPIRES, VECTOR_KEY))->toBe(VECTOR_TOKEN);
});

/*
| ⚠️ THE CONTROL THAT MAKES THE ASSERTION ABOVE MEAN SOMETHING.
|
| Without it, the vector could be passing for a reason unrelated to `token_path` —
| and it is the exact digest the previous, broken formula produced, so this is also a
| permanent record of what regressing looks like.
*/
it('does not match the formula that omits token_path', function (): void {
    $withoutSigningData = 'HS256-'.rtrim(strtr(base64_encode(
        hash_hmac('sha256', VECTOR_PATH.VECTOR_EXPIRES, VECTOR_KEY, true)
    ), '+/', '-_'), '=');

    expect($withoutSigningData)->not->toBe(VECTOR_TOKEN)
        ->and(tokenFor(VECTOR_PATH, VECTOR_EXPIRES, VECTOR_KEY))->not->toBe($withoutSigningData);
});

/*
| The signature is over the DIRECTORY, which is what lets one token cover every
| segment. A vector for a file path would pass over an implementation that signed the
| manifest alone — the very defect `token_path` exists to prevent — so the guard also
| pins that the path it signs is the path it announces.
*/
it('signs the directory it announces, so the two cannot drift apart', function (): void {
    $directory = '/9f8e7d6c-1234-4321-abcd-000000000001/';

    expect(tokenFor($directory, VECTOR_EXPIRES, VECTOR_KEY))
        ->not->toBe(tokenFor($directory.'playlist.m3u8', VECTOR_EXPIRES, VECTOR_KEY));
});

// A key rotation must change every token. Trivial, and it is the one property an
// implementation that dropped the key from the message would still fail.
it('depends on the security key', function (): void {
    expect(tokenFor(VECTOR_PATH, VECTOR_EXPIRES, VECTOR_KEY))
        ->not->toBe(tokenFor(VECTOR_PATH, VECTOR_EXPIRES, 'a-different-key'));
});
