<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

/**
 * Turns a pasted link into the frame url WE will render, or refuses it
 * (032 · FR-002 · FR-003 · FR-004).
 *
 * ⚠️ THE ONE SPELLING ON THE SERVER, and it does not repeat YouTube's: the
 * YouTube arm delegates to {@see PromoVideoUrl::extract()}, which has been the
 * single extractor since 018. A second regex for the same host is the
 * two-spellings defect this repository has paid for repeatedly, and here its
 * failure direction is a string reaching an `iframe src` through the door that
 * did not check.
 *
 * ⚠️ AND THE POINT IS WHAT IS STORED, NOT WHAT IS CHECKED — 018's rule applied
 * to a second host. Only the built url is ever written to `lessons.external_url`,
 * so what the teacher pasted cannot sit in the database one line away from a
 * frame waiting for a later reader to use it.
 *
 * ⚠️ `frontend/src/lib/video-embed.ts` IS A SECOND SPELLING AND IT ALREADY
 * DIFFERS — it answers `null` for `youtube.com/live/{id}`, which this one
 * accepts. It serves the teacher's promo video and the lesson path never calls
 * it (the `src` arrives built), so the divergence does not reach this feature —
 * but the claim "one spelling in the whole tree" would be false and nothing is
 * built on it.
 *
 * ⚠️ VIMEO IS A SECOND HOST THE OWNER ASKED FOR BY NAME, and its price is
 * declared rather than hidden: 018 says a second host means a second extractor
 * AND a second embed shape, which is exactly why storage here is a URL instead
 * of the ID 018 stores. With YouTube alone, `extract()` and one constant would
 * have done, and this class would not exist.
 */
final class EmbeddedVideoUrl
{
    /**
     * Vimeo ids are numeric. Six to twelve digits covers everything the platform
     * has issued; a closed shape is refused at the door rather than passed along
     * hopefully, exactly as {@see PromoVideoUrl} refuses an unknown id alphabet.
     */
    private const VIMEO = '~^https://(?:(?:www\.|player\.)?vimeo\.com)/(?:video/)?(?<id>[0-9]{6,12})(?:[/?&#].*)?$~';

    /**
     * The frame url, or null when the link is not one we accept.
     *
     * ⚠️ NEVER GUESSES. An unrecognised shape is `null`, and the caller's job is
     * to refuse with a sentence naming what is accepted — not to store the paste
     * and hope a later reader validates it.
     *
     * ⚠️ `https` ONLY, inherited in both arms. An `http` link embedded from an
     * https page is blocked as mixed content, silently, with the browser as the
     * only place the refusal appears.
     *
     * ⚠️ `youtube-nocookie` IS IN THE OUTPUT, not only accepted in the input.
     * The page is public and opened by a visitor who was asked for nothing;
     * planting a tracker there is a decision nobody made on purpose.
     */
    public static function build(string $raw): ?string
    {
        $raw = trim($raw);

        $youTubeId = PromoVideoUrl::extract($raw);

        if ($youTubeId !== null) {
            return 'https://www.youtube-nocookie.com/embed/'.$youTubeId;
        }

        if (preg_match(self::VIMEO, $raw, $matches) === 1) {
            return 'https://player.vimeo.com/video/'.$matches['id'];
        }

        return null;
    }

    public static function accepts(string $raw): bool
    {
        return self::build($raw) !== null;
    }

    /**
     * The refusal, in one place.
     *
     * A message reading «غير صالح» sends the teacher hunting for their own
     * mistake; this one names what is accepted and shows the shape.
     */
    public static function refusal(): string
    {
        return 'الرابط غير مقبول. الصق رابط فيديو من يوتيوب أو فيميو، مثل https://youtu.be/…';
    }
}
