<?php

declare(strict_types=1);

use App\Modules\Courses\Support\PromoVideoUrl;

/*
| Spec 018 · T014 — vectors for the link extractor.
|
| No network, no account, no fixture: the same shape as `BunnyTokenVectorTest`.
| What this pins is the ONE thing FR-008 rests on — that nothing but an eleven
| character id ever comes out of a pasted string.
|
| ⚠️ AND THE REFUSALS ARE THE HALF THAT MATTERS. A test of the accepted forms
| alone passes just as well against an extractor that accepts everything and
| returns the tail of whatever it was given.
*/

it('extracts the id from every accepted link shape', function (string $url): void {
    expect(PromoVideoUrl::extract($url))->toBe('dQw4w9WgXcQ');
})->with([
    'short' => 'https://youtu.be/dQw4w9WgXcQ',
    'watch' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'watch bare host' => 'https://youtube.com/watch?v=dQw4w9WgXcQ',
    'watch mobile' => 'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'embed nocookie' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    'shorts' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'live' => 'https://www.youtube.com/live/dQw4w9WgXcQ',
]);

/*
| A real paste carries a timestamp, a playlist, a tracking parameter. The id
| comes out alone — if any of this survived into storage it would survive into
| the frame's address.
*/
it('keeps only the id when the link carries extra parameters', function (string $url): void {
    expect(PromoVideoUrl::extract($url))->toBe('dQw4w9WgXcQ');
})->with([
    'timestamp on short' => 'https://youtu.be/dQw4w9WgXcQ?t=42',
    'playlist after v' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123&index=2',
    'parameter before v' => 'https://www.youtube.com/watch?app=desktop&v=dQw4w9WgXcQ',
    'fragment' => 'https://www.youtube.com/embed/dQw4w9WgXcQ#t=10',
]);

it('refuses everything it does not recognise', function (string $url): void {
    expect(PromoVideoUrl::extract($url))->toBeNull();
})->with([
    'a script url' => 'javascript:alert(1)',
    'a data url' => 'data:text/html,<script>alert(1)</script>',
    'another platform' => 'https://vimeo.com/123456789',
    // Look-alike host: the pattern is anchored, so a prefix cannot smuggle one in.
    'look-alike host' => 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
    'not a url at all' => 'شاهد الفيديو عندي',
    'empty' => '',
    // Plain http would be blocked as mixed content from an https page —
    // silently, with no message anywhere. Refusing it here is the honest answer.
    'insecure scheme' => 'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
    // The channel, not a video: there is nothing to embed.
    'a channel' => 'https://www.youtube.com/@someteacher',
    'id too short' => 'https://youtu.be/dQw4w9WgX',
]);

it('answers accepts() in step with extract()', function (): void {
    expect(PromoVideoUrl::accepts('https://youtu.be/dQw4w9WgXcQ'))->toBeTrue()
        ->and(PromoVideoUrl::accepts('https://vimeo.com/123456789'))->toBeFalse();
});
