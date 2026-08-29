<?php

declare(strict_types=1);

use App\Modules\CMS\Actions\ListPublicArticles;
use App\Modules\CMS\Models\Article;
use App\Shared\Support\WorkspaceContext;

/*
| SC-011 — «١٠٠٪ من المنشورِ وصفرٌ من غيرِه» in the sitemap.
|
| ⚠️ THE SITEMAP READS `ListPublicArticles`, WHICH IS WHY THIS TEST IS HERE AND
| NOT ONLY IN VITEST. A second query spelled for the map is how the criterion
| drifts into two answers that each look right in isolation: the blog shows an
| article the map omits, or — the expensive direction — the map advertises a draft
| and a crawler is sent to a 404. The Next route maps the feed to XML and owns
| nothing but the shape.
|
| The feed is also what `generateSitemaps()` chunks against, so the page size and
| the total are part of the contract rather than an implementation detail.
*/

/** @param array<string, mixed> $attributes */
function sitemapArticle(bool $participates = true, array $attributes = []): Article
{
    $workspace = marketplaceWorkspace('Academy '.uniqid(), participates: $participates);

    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'status' => 'published',
            'published_at' => now()->subDay(),
            ...$attributes,
        ]),
    );
}

it('carries every published article and nothing else', function (): void {
    $published = collect(range(1, 4))->map(fn (int $i): Article => sitemapArticle(
        attributes: ['title' => "منشور {$i}"],
    ));

    // One of each way an article can fail to be public. All four are invisible to
    // the same predicate, which is the point of there being one predicate.
    sitemapArticle(attributes: ['status' => 'draft', 'published_at' => null]);
    sitemapArticle(attributes: ['published_at' => now()->addWeek()]);
    sitemapArticle(participates: false);
    sitemapArticle()->delete();

    $this->asGuest();

    $feed = app(ListPublicArticles::class)->handle(perPage: ListPublicArticles::MAX_PER_PAGE);

    expect($feed->total())->toBe(4)
        ->and($feed->pluck('slug')->sort()->values()->all())
        ->toBe($published->pluck('slug')->sort()->values()->all());
});

it('reaches every article across the chunk boundary', function (): void {
    foreach (range(1, 5) as $i) {
        sitemapArticle(attributes: ['title' => "مقال {$i}"]);
    }

    $this->asGuest();

    $seen = [];

    // `generateSitemaps()` walks the feed page by page; a map built from page one
    // alone is a map that silently stops at the chunk size.
    for ($page = 1; $page <= 3; $page++) {
        foreach (app(ListPublicArticles::class)->handle(page: $page, perPage: 2) as $article) {
            $seen[] = $article->slug;
        }
    }

    expect($seen)->toHaveCount(5)
        ->and(array_unique($seen))->toHaveCount(5);
});

it('refuses a page size a caller invented', function (): void {
    sitemapArticle();

    $this->asGuest();

    // An unbounded page on a public route is a way to make the server do
    // arbitrary work per request; the sitemap is the only caller that wants a
    // large one, and it asks for the documented maximum.
    $this->getJson('/api/v1/public/articles?per_page=100000')->assertStatus(422);
    $this->getJson('/api/v1/public/articles?per_page='.ListPublicArticles::MAX_PER_PAGE)->assertOk();
});

it('orders the feed newest first', function (): void {
    $old = sitemapArticle(attributes: ['published_at' => now()->subMonth()]);
    $new = sitemapArticle(attributes: ['published_at' => now()->subHour()]);

    $this->asGuest();

    expect($this->getJson('/api/v1/public/articles')->json('data.*.slug'))
        ->toBe([$new->slug, $old->slug]);
});
