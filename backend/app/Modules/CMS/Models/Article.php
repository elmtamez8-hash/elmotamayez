<?php

declare(strict_types=1);

namespace App\Modules\CMS\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\CMS\Jobs\PingSearchEnginesJob;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPubliclyListed;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\CMS\ArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * ⚠️ `SoftDeletes` WAS MISSING WHILE THE TABLE HAD `softDeletes()` SINCE JULY, and
 * two migration docblocks shipped in spec 011's own first phase reasoning from a
 * trait that was not on the model: the dedupe migration says «a trashed article
 * holds its slug against the whole table» and the index migration writes down that
 * price as the trade it accepts. Both were false — `destroy()` hard-deleted the
 * row and `deleted_at` was a column nothing ever wrote. Adding the trait makes the
 * schema and the reasoning agree; it also gives `publicListingConstraints()` its
 * «غيرُ محذوف» half for nothing, since the global scope carries it.
 *
 * @property string $status
 * @property string|null $cover_path
 * @property string|null $summary
 *                                ⚠️ `array<int, mixed>` لا شكلاً موصوفاً: العمودُ JSON، وما فيه هو ما كُتِبَ
 *                                فيه — من مُكرِّرِ اللوحةِ اليومَ، ومن هجرةٍ أو بذرةٍ أو شكلٍ أقدمَ غداً. وصفُ
 *                                الشكلِ هنا يجعلُ الحارسَ في `PublicArticleResource` يبدو ميّتاً للمُحلِّلِ
 *                                بينما هو الشيءُ الوحيدُ الذي يمنعُ سؤالاً بلا جوابٍ من دخولِ البياناتِ
 *                                المنظَّمة.
 * @property array<int, mixed>|null $faq
 * @property bool $is_indexable
 */
class Article extends BaseModel
{
    /** @use HasFactory<ArticleFactory> */
    use BelongsToWorkspace, HasFactory, HasSlug, HasUuid, IsPubliclyListed, IsPublishable, SoftDeletes;

    protected $table = 'cms_articles';

    protected $fillable = [
        'workspace_id',
        'title',
        'slug',
        'body',
        'excerpt',
        // ⚠️ في `$fillable` مع الهجرةِ نفسِها. عمودٌ تُضيفُه هجرةٌ ولا يدخلُ هذه
        // القائمةَ عمودٌ **لا يُكتَبُ أبداً بصمت**: الإسنادُ الجَماعيُّ يُسقِطُ
        // المفتاحَ بلا استثناءٍ ولا سجلّ، والاستجابةُ تُعيدُ ما أُرسِلَ لا ما
        // خُزِّن. شُحِنَت ثلاثةُ أعمدةٍ هكذا في `student_profiles` (٠١٣) وبقيَت
        // بوّابةُ موافقةِ وليِّ الأمرِ معطّلةً لكلِّ من سجّلَ بنفسِه.
        'cover_path',
        'summary',
        'faq',
        'is_indexable',
        'status',
        'published_at',
        'author_id',
        'category_id',
        'seo_title',
        'seo_description',
        'canonical_url',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            // ⚠️ مصبوبٌ إلى مصفوفة: بلا الصبِّ يُقرَأُ العمودُ نصَّ JSON خامّاً،
            // فتُصيَّرُ البياناتُ المنظَّمةُ سلسلةً واحدةً بدلَ قائمةِ أسئلة —
            // ولا خطأَ في أيِّ مكان.
            'faq' => 'array',
            'is_indexable' => 'boolean',
        ];
    }

    /**
     * FR-037 — «النشر أو التحديث يجب أن يُبلَّغ به محرك البحث آلياً».
     *
     * ⚠️ ON THE MODEL, NOT IN THE CONTROLLER, BECAUSE THERE ARE TWO ENTRANCES.
     * The API's `publish()` is one; `CmsArticleResource` in the panel is the
     * other, and it writes the row directly with no controller anywhere near it.
     * A rule spelled at one door is a rule the other door does not have —
     * `FreezePeriod::booted()` announces its own changes for exactly this reason,
     * so a third write path added later is covered the day it lands.
     *
     * ⚠️ AND ONLY FOR AN ARTICLE THE PUBLIC CAN ACTUALLY READ. Submitting a
     * draft's URL asks a crawler to fetch a page that answers 404, which is the
     * one thing that costs a site standing with the engine it just invited. The
     * condition is this model's own public predicate — status, the publication
     * date, the soft-delete scope AND the workspace's participation — asked as a
     * query rather than re-derived from `status`, which would announce a
     * scheduled article a week early and an unlisted workspace's blog for ever.
     *
     * Nothing is announced on delete: the protocol has no «forget this», and a
     * 404 is how a crawler learns a page is gone.
     */
    protected static function booted(): void
    {
        static::saved(function (self $article): void {
            // The cheap half first: a draft is most of the saves this table sees,
            // and it must not cost a query to say no to.
            if ($article->status !== 'published') {
                return;
            }

            /*
            | ⚠️ `withoutWorkspaceScope()`, AND IT IS NOT AN OPTIMISATION.
            | «Can the public read this row» has nothing to do with whoever
            | happened to save it — a queued job or a console command saving an
            | article would otherwise ask the question inside a tenant that is not
            | the article's. And `WorkspaceContext` CACHES its first resolution,
            | so a query here is also a resolution here: a fixture that creates a
            | published article before signing anybody in froze the context to
            | null for the rest of that test, and `WorkspaceScope` adds no
            | condition when the id is null — every later cross-workspace
            | assertion in the same test then passes through a door that is no
            | longer shut. `CMSTest`'s isolation case turned green-to-red on
            | exactly that within an hour of this hook being written.
            */
            $readable = self::query()
                ->withoutWorkspaceScope()
                ->publiclyListed()
                ->whereKey($article->getKey())
                ->exists();

            if ($readable) {
                PingSearchEnginesJob::dispatch(['/blog/'.$article->slug]);
            }
        });
    }

    /**
     * The public URL of an article (011 · US5), through spatie/laravel-sluggable.
     *
     * ⚠️ `usingLanguage('')` IS THE WHOLE ARABIC DECISION, AND THE DEFAULT IS
     * `'en'`. `Str::slug()` transliterates through `Str::ascii($title, $language)`
     * unless the language is falsy — so the default turned «خطة المراجعة
     * النهائية» into `kht-almragaa-alnhayy`: not junk, but not a word anybody on
     * this platform reads, on the one piece of an article a search engine shows
     * in full. With it empty the Arabic survives, harakat are dropped (they are
     * `\p{M}`, outside the `\pL\pN` keep-set), percent-encoded UTF-8 is valid in a
     * path, and the browser renders it decoded.
     *
     * ⚠️ AND THE RANDOM SUFFIX IS GONE. `store()` built every slug as
     * `Str::slug($title.'-'.Str::random(6))` — a uniqueness guard bolted onto the
     * one field whose whole job is to be readable, so every article the product
     * has ever published ends in six random characters. The package answers
     * uniqueness by asking the table, and its query is exactly the one this table
     * needs: `withoutGlobalScopes()` (the index is platform-wide, so the tenant
     * scope would narrow the very question) plus `withoutGlobalScope(
     * SoftDeletingScope::class)` (a trashed article holds its slug against the
     * whole platform, because a unique index knows nothing about `deleted_at`).
     *
     * ⚠️ `doNotGenerateSlugsOnUpdate()`: a published URL that changes is a URL
     * that 404s, and every share of it is a dead link. Renaming the article
     * renames the heading and nothing else. A teacher who deliberately sends a new
     * slug still gets one — the attribute is mass-assigned and this hook simply
     * does not overwrite it — and the panel's own `unique()` on that field is
     * what refuses a collision with a sentence instead of a raw integrity error.
     * (It lived on `CreateArticleRequest` until the API was deleted on
     * 2026-09-05, and moved with the capability rather than dying with it.)
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            // A closure rather than `generateSlugsFrom('title')` for one reason: a
            // title that is entirely punctuation slugifies to the empty string,
            // and the package's fallback for that is the slug `-1`.
            ->generateSlugsFrom(fn (self $article): string => Str::slug((string) $article->title, '-', '') === ''
                ? 'مقال'
                : (string) $article->title)
            ->saveSlugsTo('slug')
            ->usingLanguage('')
            ->doNotGenerateSlugsOnUpdate()
            // `string(255)`, with room for the `-2` a collision appends.
            ->slugsShouldBeNoLongerThan(240)
            ->startSlugSuffixFrom(2);
    }

    /**
     * What an anonymous visitor may be shown (011 · FR-032 · FR-033).
     *
     * ⚠️ `WorkspaceScope` ADDS NO CONDITION FOR A GUEST, so a public query
     * without this scope returns every workspace's drafts. The workspace's
     * participation half comes from the trait; this is the row's own half.
     *
     * ⚠️ AND `published_at` IS REQUIRED TO BE PAST, not merely non-null, which is
     * a stricter answer than `isPublished()` gives. That method treats a null
     * timestamp as «published now» — correct for a member reading their own
     * workspace, wrong for the public index, where the ordering, the sitemap's
     * `lastModified` and the article's own byline all read that column. A future
     * date is therefore a scheduled article and not a published one, which is
     * scheduling implemented by not implementing it.
     *
     * «غيرُ محذوف» needs no clause here: `SoftDeletes` puts it in a global scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function publicListingConstraints(Builder $query): Builder
    {
        return $query
            ->where('cms_articles.status', 'published')
            ->whereNotNull('cms_articles.published_at')
            ->where('cms_articles.published_at', '<=', now());
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'cms_article_tag', 'article_id', 'tag_id');
    }
}
