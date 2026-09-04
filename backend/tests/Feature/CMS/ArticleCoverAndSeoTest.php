<?php

declare(strict_types=1);

use App\Modules\CMS\Models\Article;
use App\Shared\Support\WorkspaceContext;

/**
 * غلافُ المقالِ وحقولُ محرّكاتِ البحثِ والإجابة.
 *
 * ⛔ **التوكيدُ على الصفِّ المخزَّنِ لا على الاستجابة.** الإسنادُ الجَماعيُّ يُسقِطُ
 * مفتاحاً غيرَ موجودٍ في `$fillable` **بصمت** — لا استثناءَ ولا سطرَ سجلّ — وردُّ
 * الإنشاءِ يُصيِّرُ ما أُرسِلَ لا ما خُزِّن. هكذا شُحِنَت ثلاثةُ أعمدةٍ في
 * `student_profiles` (٠١٣) وبقيَت بوّابةُ موافقةِ وليِّ الأمرِ معطّلةً لكلِّ من
 * سجّلَ بنفسِه، وكلُّ توكيدٍ في تلك المواصفةِ كان صحيحاً ولا يرى شيئاً.
 */
beforeEach(function (): void {
    /*
    | ⚠️ مساحةٌ **مشارِكةٌ في السوقِ العامّ**، لا مساحةٌ عاديّة. `WorkspaceScope`
    | لا يُضيفُ شرطاً لزائرٍ بلا حساب، فالحارسُ هو `publiclyListed()` وحدَه —
    | ومساحةٌ لم تُشارِكْ تُجيبُ ٤٠٤ عن كلِّ مقالٍ فيها. تجهيزةٌ بمساحةٍ عاديّةٍ
    | تقيسُ الرفضَ لا الحمولة.
    */
    $this->workspace = marketplaceWorkspace('أكاديميّة الغلاف', participates: true);
});

/**
 * مقالٌ داخلَ مساحةِ التجهيزة.
 *
 * ⚠️ `forWorkspace` لا `set`: السياقُ مفردةٌ تُخزِّنُ ما حلَّته، وتركُه محلولاً
 * يسري على ما بعدَه في العمليّةِ نفسِها.
 *
 * @param  array<string, mixed>  $attributes
 */
function article(array $attributes): Article
{
    $workspace = test()->workspace;

    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::query()->create([
            'workspace_id' => $workspace->getKey(),
            ...$attributes,
        ]),
    );
}

it('stores the cover and the answer-engine fields instead of dropping them', function (): void {
    $article = article([
        'title' => 'خطّة المراجعة النهائيّة',
        'body' => 'نصّ المقال.',
        'excerpt' => 'مقتطف.',
        'cover_path' => 'article-covers/plan.webp',
        'summary' => 'ابدأْ بالمراجعة قبل أسبوعين، بجلسات قصيرة يوميّة.',
        'faq' => [['question' => 'متى أبدأ؟', 'answer' => 'قبل أسبوعين.']],
        'is_indexable' => false,
        'status' => 'published',
        'published_at' => now(),
    ]);

    // ⚠️ `fresh()` لا الكائنُ في الذاكرة: هذا الأخيرُ يحملُ ما أُسنِدَ إليه سواءٌ
    // وصلَ القاعدةَ أم لا، وهو بالضبطِ ما يُخفي عطلَ `$fillable`.
    $stored = $article->fresh();

    expect($stored?->cover_path)->toBe('article-covers/plan.webp')
        ->and($stored?->summary)->toBe('ابدأْ بالمراجعة قبل أسبوعين، بجلسات قصيرة يوميّة.')
        // مصبوبٌ إلى مصفوفة: بلا الصبِّ يعودُ نصَّ JSON خامّاً.
        ->and($stored?->faq)->toBe([['question' => 'متى أبدأ؟', 'answer' => 'قبل أسبوعين.']])
        ->and($stored?->is_indexable)->toBeFalse();
});

it('keeps an article indexable unless somebody decides otherwise', function (): void {
    /*
    | ⚠️ الافتراضُ هو المتطلَّب. مقالٌ كُتِبَ قبلَ هذا العمودِ يجبُ أن يبقى
    | مفهرَساً؛ افتراضٌ معكوسٌ يُخفي المدوّنةَ كلَّها من نتائجِ البحثِ في هجرةٍ
    | واحدةٍ بلا خطأٍ في أيِّ مكان.
    */
    $article = article([
        'title' => 'مقال بلا حقول',
        'body' => 'نصّ.',
        'status' => 'published',
        'published_at' => now(),
    ]);

    expect($article->fresh()?->is_indexable)->toBeTrue();
});

it('sends the cover on the card and the summary only on the article', function (): void {
    article([
        'title' => 'مقال بغلاف',
        'slug' => 'covered-article',
        'body' => 'نصّ المقال.',
        'cover_path' => 'article-covers/plan.webp',
        'summary' => 'خلاصة.',
        'faq' => [
            ['question' => 'سؤال مكتمل', 'answer' => 'جواب.'],
            // ⚠️ زوجٌ ناقصٌ عمداً: سؤالٌ بلا جوابٍ يُنتِجُ عقدةَ `Question` عرجاءَ
            // في البياناتِ المنظَّمة، وهي أسوأُ من غيابِ القسمِ كلِّه.
            ['question' => 'سؤال بلا جواب', 'answer' => '  '],
        ],
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);

    $card = $this->getJson('/api/v1/public/articles')->assertOk()->json('data.0');

    // الفهرسُ هو من يعرضُ اثنتَي عشرةَ صورة: غلافٌ يصلُ صفحةَ المقالِ وحدَها يترك
    // البطاقاتِ كلَّها بلا صورةٍ بلا خطأٍ في أيِّ مكان.
    expect($card['cover_url'])->toContain('article-covers/plan.webp')
        // ولا خلاصةَ ولا أسئلةَ على البطاقة: عرضُ نطاقٍ لا يقرؤه أحد.
        ->and($card)->not->toHaveKey('summary')
        ->and($card)->not->toHaveKey('faq');

    $detail = $this->getJson('/api/v1/public/articles/covered-article')->assertOk()->json('data');

    expect($detail['summary'])->toBe('خلاصة.')
        ->and($detail['cover_url'])->toContain('article-covers/plan.webp')
        ->and($detail['is_indexable'])->toBeTrue()
        // الناقصُ مُصفّىً في الخلفيّة، فالواجهةُ والبياناتُ المنظَّمةُ تقرآنِ قائمةً
        // واحدةً — تصفيةٌ في إحداهما تتركُ الأخرى تصفُ سؤالاً بلا جواب.
        ->and($detail['faq'])->toBe([['question' => 'سؤال مكتمل', 'answer' => 'جواب.']]);
});

it('carries no cover url at all when nothing was uploaded', function (): void {
    article([
        'title' => 'مقال بلا غلاف',
        'slug' => 'bare-article',
        'body' => 'نصّ.',
        'status' => 'published',
        'published_at' => now(),
    ]);

    // ⚠️ `null` لا سلسلةٌ تنتهي بـ`storage/`: الواجهةُ تفرِّقُ بينَ «لا غلافَ» و
    // «غلافٌ عنوانُه مكسور»، والثانيةُ تُصيَّرُ مربّعاً مكسوراً على كلِّ بطاقة.
    expect($this->getJson('/api/v1/public/articles/bare-article')->assertOk()->json('data.cover_url'))
        ->toBeNull();
});
