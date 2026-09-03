<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

/**
 * Turns a pasted link into a video id, or refuses it (018 · FR-007 · FR-008).
 *
 * ⚠️ THE ONE SPELLING. `SetCoursePromoVideo` and `UpdateCourseRequest` both
 * call this — a second regex somewhere else is the two-spellings defect this
 * repository has paid for repeatedly, and here its failure direction is a
 * string reaching an `iframe src` through the door that did not check.
 *
 * ⚠️ AND THE POINT IS WHAT IS STORED, NOT WHAT IS CHECKED. Only the extracted
 * id is ever written, so a raw URL cannot sit in the database one line away
 * from a frame waiting for a later reader to use it. That makes FR-008
 * unrepresentable rather than guarded.
 *
 * One platform for now, deliberately: a second means a second extractor AND a
 * second embed shape, measured when it is asked for rather than guessed at now.
 *
 * The accepted list is not claimed to be exhaustive of everything the platform
 * publishes. An unrecognised shape is REFUSED AT THE DOOR with a sentence
 * naming what is accepted — which is the correct behaviour for a shape we do
 * not understand, and the reason the refusal is not deferred to display time.
 */
final class PromoVideoUrl
{
    /*
    | The id alphabet, pinned. Eleven characters of unreserved URL-safe text is
    | the shape the platform has published for its whole life; anything outside
    | it is refused rather than passed along hopefully.
    */
    private const ID = '[A-Za-z0-9_-]{11}';

    /**
     * The host forms we accept. Ordered longest-path-first so `/embed/` and
     * `/shorts/` are not swallowed by a looser pattern above them.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        // youtu.be/<id>
        '~^https://(?:www\.)?youtu\.be/(?<id>'.self::ID.')(?:[?&#].*)?$~',
        // youtube.com/watch?v=<id>  — the id may sit anywhere in the query
        '~^https://(?:www\.|m\.)?youtube\.com/watch\?(?:.*&)?v=(?<id>'.self::ID.')(?:[&#].*)?$~',
        // youtube.com/embed/<id> and youtube-nocookie.com/embed/<id>
        '~^https://(?:www\.)?youtube(?:-nocookie)?\.com/embed/(?<id>'.self::ID.')(?:[?&#].*)?$~',
        // youtube.com/shorts/<id>
        '~^https://(?:www\.|m\.)?youtube\.com/shorts/(?<id>'.self::ID.')(?:[?&#].*)?$~',
        // youtube.com/live/<id>
        '~^https://(?:www\.)?youtube\.com/live/(?<id>'.self::ID.')(?:[?&#].*)?$~',
    ];

    /**
     * The video id, or null when the link is not one we accept.
     *
     * ⚠️ `https` ONLY, in every pattern. An `http` link would be embedded from
     * an https page and blocked as mixed content — silently, with the CDN as
     * the only place the refusal appears. Refusing it here is the honest answer.
     */
    public static function extract(string $url): ?string
    {
        $url = trim($url);

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches['id'];
            }
        }

        return null;
    }

    public static function accepts(string $url): bool
    {
        return self::extract($url) !== null;
    }
}
