<?php

declare(strict_types=1);

namespace App\Modules\CMS\Actions;

use App\Modules\CMS\Models\Article;
use App\Modules\CMS\Models\Category;
use App\Modules\CMS\Models\Tag;
use App\Shared\Actions\Action;
use App\Shared\Scopes\WorkspaceScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * أبوابُ التصفّحِ في فهرسِ المدوّنة: التصنيفاتُ والوسومُ التي لها مقالٌ منشورٌ فعلاً.
 *
 * ⚠️ **وُجِدَ لأنّ المرشِّحَ كان مبنيّاً بلا بابٍ يصلُه.** `/blog` يقبلُ
 * `?category=` و`?tag=` ويُصفّي بهما منذُ ٠١١، والطريقُ الوحيدُ إليهما كان بطاقةً
 * تحملُ ذلك التصنيفَ بالصدفةِ في الصفحةِ المعروضة — «إذنٌ لا يصلُه رابط» في ثوبِ
 * تصفّح، وهي العائلةُ التي دفعَ هذا المستودعُ ثمنَها في `settlement.requestRate`
 * و`writeBans.lift`.
 *
 * ⚠️ **والعدُّ يُشتَقُّ من `publiclyListed()` نفسِه، لا من شرطٍ مكتوبٍ ثانيةً.**
 * `WorkspaceScope` لا يضيفُ شرطاً لزائرٍ بلا حساب، فمُرشِّحٌ مكتوبٌ بيدٍ هنا
 * («منشور» و«تاريخُه مضى») هو الهجاءُ الثاني لسؤالٍ واحد — ويُظهِرُ في الشريطِ
 * تصنيفاً كلُّ مقالاتِه مسوّدات، فيفتحُ القارئُ صفحةً فارغة. الشرطُ يدخلُ
 * استعلاماً فرعيّاً كما هو.
 *
 * ⚠️ **والتجميعُ بالـslug لا بالصفّ**: `cms_categories` صفوفٌ لكلِّ مساحةِ عمل،
 * فمدرّسانِ يكتبانِ «نصائح للطلاب» صفّانِ بالـslug نفسِه — والفهرسُ يُصفّي
 * بالـslug عبرَ المساحاتِ كلِّها، فشريطٌ مبنيٌّ على الصفوفِ يعرضُ الشريحةَ نفسَها
 * مرّتَينِ تفتحانِ القائمةَ نفسَها. و`MIN(name)` لأنّ `ONLY_FULL_GROUP_BY` يرفضُ
 * عموداً غيرَ مجمَّعٍ خارجَ `GROUP BY` — واسمانِ لـslug واحدٍ يجبُ أن يُحسَما
 * بقاعدةٍ ثابتة لا بما يعودُ أوّلاً.
 */
class ListPublicArticleTopics extends Action
{
    /**
     * سقفُ الوسومِ في الشريط.
     *
     * الوسومُ حرّةٌ بلا قائمةٍ مغلقة، فمنصّةٌ فيها مئةُ مدرّسٍ تُنتِجُ شريطاً
     * بمئتَي شريحة — وهو قائمةٌ لا تصفّح. التصنيفاتُ بلا سقفٍ لأنّها بابٌ أعلى
     * وأقلُّ عدداً بطبيعتِها.
     */
    public const MAX_TAGS = 12;

    /**
     * @return array{
     *     categories: list<array{slug: string, name: string, articles_count: int}>,
     *     tags: list<array{slug: string, name: string, articles_count: int}>
     * }
     */
    public function handle(): array
    {
        return [
            'categories' => $this->shape(
                Category::query()
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->join('cms_articles', 'cms_articles.category_id', '=', 'cms_categories.id')
                    ->whereIn('cms_articles.id', $this->publishedIds())
                    ->groupBy('cms_categories.slug')
                    ->select([
                        'cms_categories.slug',
                        DB::raw('MIN(cms_categories.name) as name'),
                        DB::raw('COUNT(*) as articles_count'),
                    ])
                    ->orderByDesc('articles_count')
                    ->orderBy('cms_categories.slug')
                    ->toBase()
                    ->get(),
            ),
            'tags' => $this->shape(
                Tag::query()
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->join('cms_article_tag', 'cms_article_tag.tag_id', '=', 'cms_tags.id')
                    ->whereIn('cms_article_tag.article_id', $this->publishedIds())
                    ->groupBy('cms_tags.slug')
                    ->select([
                        'cms_tags.slug',
                        DB::raw('MIN(cms_tags.name) as name'),
                        DB::raw('COUNT(*) as articles_count'),
                    ])
                    ->orderByDesc('articles_count')
                    ->orderBy('cms_tags.slug')
                    ->limit(self::MAX_TAGS)
                    ->toBase()
                    ->get(),
            ),
        ];
    }

    /**
     * مفاتيحُ المقالاتِ المنشورةِ علناً — الشرطُ نفسُه الذي يبني الفهرس.
     *
     * استعلامٌ فرعيٌّ لا قائمةُ مفاتيحَ مُحضَرة: قائمةٌ تعني `WHERE id IN (…)`
     * بطولِ المدوّنةِ كلِّها في كلِّ فتحةِ صفحة.
     */
    private function publishedIds(): QueryBuilder
    {
        return Article::query()
            ->publiclyListed()
            ->select('cms_articles.id')
            ->toBase();
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{slug: string, name: string, articles_count: int}>
     */
    private function shape(Collection $rows): array
    {
        // `array_values()` فوقَ `values()->all()`: الأولى تُنتِجُ قائمةً وقتَ
        // التشغيل، والثانية هي ما يُثبِتُ ذلك للمحلّل — نفسُ ما احتاجَه
        // `everMemberCohortIdsFor()` بعدَ `pluck()->all()`.
        return array_values($rows->map(static fn (\stdClass $row): array => [
            'slug' => (string) $row->slug,
            'name' => (string) $row->name,
            'articles_count' => (int) $row->articles_count,
        ])->all());
    }
}
