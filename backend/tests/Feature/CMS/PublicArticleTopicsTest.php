<?php

declare(strict_types=1);

use App\Modules\CMS\Models\Article;
use App\Modules\CMS\Models\Category;
use App\Modules\CMS\Models\Tag;
use App\Shared\Support\WorkspaceContext;

/*
| شريطُ التصفّحِ في المدوّنة — `/public/article-topics`.
|
| ⚠️ الشرطُ هنا هو `publiclyListed()` نفسُه، وهذا ما يُقاسُ في هذا الملفّ: تصنيفٌ
| كلُّ مقالاتِه مسوّداتٌ يظهرُ في الشريطِ إن كُتِبَ الشرطُ ثانيةً بيدٍ ونُسِيَ فرعٌ
| منه — والقارئُ يضغطُ شريحةً تفتحُ قائمةً فارغة.
|
| ⚠️ واسمُ المساعدِ خاصٌّ بهذا الملفّ عمداً: دالّةُ Pest عامّةٌ في المدى، و
| `publicArticle()` معلَنةٌ في `PublicArticleExposureTest` — واسمانِ متطابقانِ
| بتوقيعَينِ مختلفَينِ خطأٌ قاتلٌ في أوّلِ عاملٍ يحمّلُ الملفَّينِ معاً.
*/

/** @param array<string, mixed> $attributes */
function blogTopicArticle(
    string $categorySlug,
    string $categoryName,
    array $attributes = [],
    bool $participates = true,
    ?string $tagSlug = null,
): Article {
    $workspace = marketplaceWorkspace('Academy '.uniqid(), participates: $participates);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use (
        $workspace,
        $categorySlug,
        $categoryName,
        $attributes,
        $tagSlug,
    ): Article {
        $category = Category::create([
            'workspace_id' => $workspace->getKey(),
            'name' => $categoryName,
            'slug' => $categorySlug,
        ]);

        $article = Article::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'category_id' => $category->getKey(),
            ...$attributes,
        ]);

        if ($tagSlug !== null) {
            $tag = Tag::create([
                'workspace_id' => $workspace->getKey(),
                'name' => 'وسم '.$tagSlug,
                'slug' => $tagSlug,
            ]);

            $article->tags()->sync([$tag->getKey()]);
        }

        return $article;
    });
}

/** @return array<string, array{slug: string, name: string, articles_count: int}> */
function blogTopicsBySlug(array $rows): array
{
    $keyed = [];

    foreach ($rows as $row) {
        $keyed[$row['slug']] = $row;
    }

    return $keyed;
}

it('offers a category whose article lives in another workspace entirely', function (): void {
    // الفهرسُ يُصفّي بالـslug عبرَ المساحاتِ كلِّها، فالشريطُ يجبُ أن يكونَ عابراً
    // مثلَه — وشريطٌ مقصورٌ على مساحةِ القارئِ يكون فارغاً لكلِّ زائر.
    blogTopicArticle('study-guide', 'نصائح للطلاب');

    $this->asGuest();

    $topics = blogTopicsBySlug(
        $this->getJson('/api/v1/public/article-topics')->assertOk()->json('data.categories'),
    );

    expect($topics)->toHaveKey('study-guide')
        ->and($topics['study-guide']['name'])->toBe('نصائح للطلاب')
        ->and($topics['study-guide']['articles_count'])->toBe(1);
});

it('hides a category whose only article is a draft', function (): void {
    blogTopicArticle('secret', 'مسوّدات', ['status' => 'draft', 'published_at' => null]);

    $this->asGuest();

    expect($this->getJson('/api/v1/public/article-topics')->json('data.categories'))->toBe([]);
});

it('hides a category whose only article is scheduled for a date that has not arrived', function (): void {
    // نفسُ الحدِّ الذي يفصلُ «منشور» عن «مجدوَل» في الفهرس. شرطٌ مكتوبٌ ثانيةً
    // هنا كان سيُسقِطُ هذا الفرعَ وحدَه، وهو أكثرُ الفروعِ نسياناً.
    blogTopicArticle('soon', 'قريباً', ['published_at' => now()->addWeek()]);

    $this->asGuest();

    expect($this->getJson('/api/v1/public/article-topics')->json('data.categories'))->toBe([]);
});

it('hides a category belonging to a workspace that left the marketplace', function (): void {
    blogTopicArticle('withdrawn', 'منسحب', participates: false);

    $this->asGuest();

    expect($this->getJson('/api/v1/public/article-topics')->json('data.categories'))->toBe([]);
});

it('draws one chip for a slug two workspaces both use, and adds their counts', function (): void {
    // صفّانِ لا واحد: `cms_categories` مملوكٌ لمساحةِ العمل، فمدرّسانِ يكتبانِ
    // التصنيفَ نفسَه صفّانِ بالـslug نفسِه — وشريحتانِ تفتحانِ القائمةَ نفسَها.
    blogTopicArticle('study-guide', 'نصائح للطلاب');
    blogTopicArticle('study-guide', 'نصائح للطلاب');

    $this->asGuest();

    $categories = $this->getJson('/api/v1/public/article-topics')->json('data.categories');

    expect($categories)->toHaveCount(1)
        ->and($categories[0]['slug'])->toBe('study-guide')
        ->and($categories[0]['articles_count'])->toBe(2);
});

it('offers a tag the same way, and every chip it offers answers with articles', function (): void {
    blogTopicArticle('study-guide', 'نصائح للطلاب', tagSlug: 'mothakara');
    blogTopicArticle('exams', 'امتحانات', ['status' => 'draft', 'published_at' => null], tagSlug: 'hidden');

    $this->asGuest();

    $payload = $this->getJson('/api/v1/public/article-topics')->assertOk()->json('data');

    expect(array_column($payload['tags'], 'slug'))->toBe(['mothakara']);

    /*
    | ⚠️ وكلُّ شريحةٍ تُمشى عبرَ الفهرسِ نفسِه. تأكيدٌ على الحمولةِ وحدَها يمرُّ
    | فوقَ بناءٍ يعرضُ شرائحَ تفتحُ صفراً — وهو العطبُ الوحيدُ الذي وُجِدَ هذا
    | المسارُ من أجلِه. نفسُ درسِ `ListLeaderboardScopes`: كلُّ خيارٍ مَعروضٍ
    | يُجرَّبُ على البابِ الحقيقيّ.
    */
    foreach ([...$payload['categories'], ...$payload['tags']] as $chip) {
        $key = in_array($chip, $payload['tags'], true) ? 'tag' : 'category';

        expect($this->getJson('/api/v1/public/articles?'.$key.'='.$chip['slug'])->json('meta.total'))
            ->toBe($chip['articles_count'], "chip {$key}={$chip['slug']} opens an empty list");
    }
});

it('answers an empty blog with empty rails rather than an error', function (): void {
    $this->asGuest();

    $payload = $this->getJson('/api/v1/public/article-topics')->assertOk()->json('data');

    expect($payload['categories'])->toBe([])->and($payload['tags'])->toBe([]);
});
