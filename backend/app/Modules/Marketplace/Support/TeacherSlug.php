<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Support\Str;

/**
 * The public URL segment for a teacher: `/teachers/ahmd-almnswry`.
 *
 * ⚠️ **It will not read the way the name is normally spelled in English.**
 * Arabic does not write short vowels, so nothing can recover them from the
 * script alone — «أحمد المنصوري» transliterates to `ahmd-almnswry`, never to
 * `ahmed-almansouri`. That is a property of the writing system, not a gap in
 * the mapping, and no library fixes it. ICU's own `Any-Latin` returns exactly
 * the same consonant skeleton.
 *
 * So the generated value is a DEFAULT, not an answer. `slug` is fillable and
 * unique, and a teacher who cares about their spelling overrides it — the demo
 * teachers in MarketplaceSeeder carry hand-written slugs to prove the column is
 * settable and to keep the seeded pages readable.
 *
 * Why not keep Arabic in the URL: it is percent-encoded the moment it leaves
 * the address bar, and a link pasted into a chat or an email arrives as
 * `%D8%A3%D8%AD%D9%85%D8%AF-...`. The owner chose Latin for that reason.
 */
final class TeacherSlug
{
    /**
     * A slug for `$name` that no other profile holds.
     *
     * `$exceptId` is the profile being saved: without it, re-saving a teacher
     * would find their OWN slug taken and hand them `ahmd-almnswry-2` — a new
     * URL on every save, and the old one dead.
     */
    public static function for(string $name, ?int $exceptId = null): string
    {
        $base = Str::slug(self::toLatin($name));

        // A name written entirely in characters the map has nothing for — and a
        // profile whose user row has been deleted. A URL segment is required, so
        // it degrades to something valid rather than to `/teachers/`.
        if ($base === '') {
            $base = 'teacher';
        }

        $slug = $base;
        $suffix = 1;

        while (self::taken($slug, $exceptId)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /**
     * ICU, not `Str::transliterate`.
     *
     * `Str::slug` alone returns an empty string for Arabic — it strips whatever
     * its ASCII map has no entry for rather than transliterating first — and
     * `Str::transliterate` mangles it differently: «فاطمة الهاشمي» comes back as
     * `ftm-at-lhshmy`, dropping the alif of «ال» and expanding ة to "at". ICU's
     * `Any-Latin` gives `fatmt-alhashmy`, which is the same consonant skeleton
     * but readable.
     *
     * intl ships with PHP but is not guaranteed enabled, so the fallback stays.
     * It produces a different slug for the same name, which matters only for a
     * profile created on a host without intl — and a wrong-looking slug is a
     * cosmetic defect where a fatal error would be an outage.
     */
    private static function toLatin(string $name): string
    {
        if (! class_exists(\Transliterator::class)) {
            return Str::transliterate($name);
        }

        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');

        if ($transliterator === null) {
            return Str::transliterate($name);
        }

        $latin = $transliterator->transliterate($name);

        return $latin === false ? Str::transliterate($name) : $latin;
    }

    /**
     * ⚠️ withoutWorkspaceScope: the slug is a PLATFORM-wide URL.
     *
     * Two teachers in different workspaces still share one `/teachers/{slug}`
     * namespace, and a scoped check would let the second one through — where the
     * unique index then rejects the insert, in production, on a signup.
     */
    private static function taken(string $slug, ?int $exceptId): bool
    {
        return TeacherProfile::query()
            ->withoutWorkspaceScope()
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}
