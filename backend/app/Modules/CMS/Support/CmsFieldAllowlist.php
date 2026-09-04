<?php

declare(strict_types=1);

namespace App\Modules\CMS\Support;

use App\Modules\Marketplace\Support\PublicFieldAllowlist;

/**
 * What a public blog payload may carry (011 · US5 · FR-034).
 *
 * On the shape of `AssessmentFieldAllowlist` and `StudentBalanceAllowlist`: one
 * list, walked by one test, so adding a field to a Resource fails the build
 * rather than a review.
 *
 * ⚠️ THIS CLASS BELONGS TO CMS AND `PublicFieldAllowlist` STAYS MARKETPLACE'S.
 * The import goes one way, deliberately: `FORBIDDEN` is a statement about any
 * public payload on the platform — «never, at any depth» — so restating it here
 * would be a second copy that ages the first time somebody adds a column. Growing
 * the Marketplace list with `body_html` and `seo_description` would be the mirror
 * mistake: that class is the set of fields a visitor may be told about a TEACHER
 * or a COURSE, and diluting it makes «is this key allowed» answerable by the
 * wrong list.
 *
 * ⚠️ `id` IS IN `FORBIDDEN`, AND THE EXISTING `ArticleResource` PUBLISHES THREE OF
 * THEM — `category.id`, every `tags[].id`, plus `body` and `status`. That resource
 * is the AUTHENTICATED one and may keep them; it is why the public route gets a
 * resource of its own rather than a `when()` inside that one. A single resource
 * branching on the reader is one forgotten branch away from publishing a draft's
 * status to a search engine.
 *
 * The nested teacher and course cards are Marketplace's shapes, reused rather
 * than restated: FR-038's related links ARE marketplace cards, and a second
 * spelling of what a teacher card contains is how the two drift.
 */
final class CmsFieldAllowlist
{
    /** What the blog index shows for one article. */
    public const ARTICLE_CARD = [
        'uuid',
        // The public URL segment. `uuid` stays because the authoring endpoints
        // are keyed by it and an old link may carry one.
        'slug',
        'title',
        'excerpt',
        'published_at',
        'updated_at',
        'category',
        'tags',
        /*
        | ⚠️ الغلافُ على البطاقةِ لا على التفصيلِ وحدَه: الفهرسُ هو من يعرضُ اثنتَي
        | عشرةَ صورة. ورابطٌ مبنيٌّ من مسارٍ نسبيّ، فالعمودُ نفسُه لا يُنشَرُ أبداً
        | — مسارُ قرصٍ في حمولةٍ عامّةٍ يصفُ شكلَ تخزينِنا لمن لا شأنَ له به.
        */
        'cover_url',
    ];

    /** The article page itself. */
    public const ARTICLE_DETAIL = [
        ...self::ARTICLE_CARD,
        /*
        | ⚠️ `body_html`, NEVER `body`. Authored text is Markdown, rendered per
        | response — a stored HTML column is an XSS sink with a teacher's keyboard
        | attached to it, and a second copy of the same words that drifts from the
        | source at the first typo fix. `MarkdownRenderer` strips raw HTML rather
        | than escaping it, so the allowlist is the Markdown feature set itself.
        */
        'body_html',
        'seo_title',
        'seo_description',
        'canonical_url',
        'related_teachers',
        'related_courses',
        /*
        | حقولُ محرّكاتِ الإجابة. الثلاثةُ على التفصيلِ وحدَه: البطاقةُ تعرضُ
        | المقتطفَ ولا مكانَ فيها لخلاصةٍ ولا لقائمةِ أسئلة، وحمولةٌ تحملُ اثنتَي
        | عشرةَ خلاصةً في كلِّ فتحةِ فهرسٍ عرضُ نطاقٍ لا يقرؤه أحد.
        |
        | ⚠️ و`is_indexable` **قرارُ نشرٍ لا سرّ**: الواجهةُ تبني منه وسمَ
        | `robots`، وهي معلومةٌ يقرؤها الزاحفُ من الصفحةِ نفسِها على أيِّ حال.
        */
        'summary',
        'faq',
        'is_indexable',
    ];

    /** A category or a tag, on a card. Never `id` — see `FORBIDDEN`. */
    public const TAXONOMY = ['slug', 'name'];

    /**
     * Every key any public CMS payload may contain, including the envelope.
     *
     * ⚠️ THE ENVELOPE KEYS ARE HERE AND NOT IN `ARTICLE_*`: `current_page` is not
     * a field of an article, and mixing the two makes a leak look listed.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([
            ...self::ARTICLE_DETAIL,
            ...self::TAXONOMY,
            ...PublicFieldAllowlist::TEACHER_CARD,
            ...PublicFieldAllowlist::COURSE_CARD,
            ...PublicFieldAllowlist::TAXONOMY,
            // Laravel's paginator envelope, in full. Listed rather than filtered
            // out of the walk: a key skipped by the test is a key nobody decided
            // about, and `path` and `url` are the two that would carry a query
            // string if a filter were ever echoed back into them.
            'data', 'meta', 'links', 'current_page', 'per_page', 'total', 'last_page',
            'first', 'last', 'prev', 'next', 'from', 'to', 'url', 'label', 'active', 'path', 'page',
            'teacher', 'name_ar', 'icon', 'teachers_count',
        ]));
    }

    /**
     * Never present, at any depth. Marketplace's list, not a copy of it.
     *
     * @return list<string>
     */
    public static function forbidden(): array
    {
        return PublicFieldAllowlist::FORBIDDEN;
    }
}
