<?php

declare(strict_types=1);

use App\Modules\CMS\Models\Article;
use App\Modules\CMS\Models\Category;
use App\Modules\CMS\Models\Tag;
use App\Modules\CMS\Support\CmsFieldAllowlist;
use App\Modules\Marketplace\Actions\Public\RelatedTeachers;
use App\Shared\Support\WorkspaceContext;

/*
| The guard test for the public blog (011 · US5 · SC-009 · SC-010).
|
| `WorkspaceScope` adds no condition when there is no authenticated user, so on a
| guest request tenant isolation is simply absent — `publiclyListed()` stands in
| its place. If this file fails, the blog is publishing drafts across every
| workspace on the platform. Stop and fix that before anything else.
|
| ⚠️ THE SENTINELS ARE ASCII, AND THAT IS NOT A STYLE CHOICE.
| `TestResponse::getContent()` escapes non-ASCII, so the body of a response
| carrying «حل زميلي» holds `حل...` — and
| `expect($response->getContent())->not->toContain('حل زميلي')` is therefore TRUE
| whatever the payload contains. Every leak assertion in this file would pass
| against a response that leaked everything. Same precedent as `DRAFT_SENTINEL`
| in the Courses suite.
|
| ⚠️ AND `PublicExposureTest` DOES NOT COVER THESE ROUTES. Its payload list is a
| hand-written array of marketplace URLs; adding a public endpoint anywhere else
| in the tree changes not one assertion in it. This file is the CMS half, and a
| third public surface will need a third.
*/

const CMS_DRAFT_SENTINEL = 'DRAFT-SENTINEL-DO-NOT-PUBLISH';

/** @param array<string, mixed> $attributes */
function publicArticle(bool $participates = true, array $attributes = []): Article
{
    $workspace = marketplaceWorkspace('Academy '.uniqid(), participates: $participates);

    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            ...$attributes,
        ]),
    );
}

it('reads a published article without any account at all', function (): void {
    $article = publicArticle(attributes: [
        'title' => 'خطة المراجعة النهائية',
        'body' => "## مقدّمة\n\nنصُّ المقال.",
    ]);

    $this->asGuest();

    $response = $this->getJson('/api/v1/public/articles/'.$article->slug);

    $response->assertOk()
        ->assertJsonPath('data.slug', $article->slug)
        ->assertJsonPath('data.title', 'خطة المراجعة النهائية');

    // Markdown is rendered per response and the raw source never travels.
    expect($response->json('data.body_html'))->toContain('<h2>')
        ->and($response->json())->not->toHaveKey('data.body');
});

it('keeps the Arabic in the URL instead of transliterating it away', function (): void {
    // `Str::slug()`'s default language turns this title into
    // `kht-almragaa-alnhayy` — the one piece of an article a search engine shows
    // in full, in a language nobody on this platform reads.
    $article = publicArticle(attributes: ['title' => 'خُطّةُ المراجعةِ النهائية', 'slug' => null]);

    expect($article->slug)->toBe('خطة-المراجعة-النهائية');

    $this->asGuest();

    $this->getJson('/api/v1/public/articles/'.rawurlencode($article->slug))->assertOk();
});

it('refuses a draft by its direct link, and says nothing about its existence', function (): void {
    $draft = publicArticle(attributes: [
        'status' => 'draft',
        'published_at' => null,
        'body' => CMS_DRAFT_SENTINEL,
    ]);

    $this->asGuest();

    $this->getJson('/api/v1/public/articles/'.$draft->slug)->assertNotFound();

    $index = $this->getJson('/api/v1/public/articles');

    expect($index->json('meta.total'))->toBe(0)
        ->and($index->getContent())->not->toContain(CMS_DRAFT_SENTINEL);
});

it('refuses an article scheduled for a date that has not arrived', function (): void {
    // A future `published_at` is a scheduled article, which is what makes
    // scheduling work without a line of scheduling code — and what makes the
    // difference between this predicate and `isPublished()` load-bearing.
    $scheduled = publicArticle(attributes: [
        'status' => 'published',
        'published_at' => now()->addWeek(),
        'body' => CMS_DRAFT_SENTINEL,
    ]);

    $this->asGuest();

    $this->getJson('/api/v1/public/articles/'.$scheduled->slug)->assertNotFound();
    expect($this->getJson('/api/v1/public/articles')->json('meta.total'))->toBe(0);
});

it('refuses an article whose workspace never opted into public publishing', function (): void {
    // T117 — the decision is that `participates_in_marketplace` is the one flag,
    // so a teacher who withdraws from the marketplace takes their blog down with
    // them. Silently, which is why the settings screen has to say so.
    $article = publicArticle(participates: false, attributes: ['body' => CMS_DRAFT_SENTINEL]);

    $this->asGuest();

    $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
    expect($this->getJson('/api/v1/public/articles')->json('meta.total'))->toBe(0);
});

it('refuses a deleted article, which used to be a row that never went away', function (): void {
    $article = publicArticle(attributes: ['body' => CMS_DRAFT_SENTINEL]);

    $article->delete();

    $this->asGuest();

    $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
    expect($this->getJson('/api/v1/public/articles')->json('meta.total'))->toBe(0);
});

it('lists articles from several participating workspaces in one feed', function (): void {
    foreach (range(1, 3) as $i) {
        publicArticle(attributes: ['title' => "مقال {$i}"]);
    }

    $this->asGuest();

    expect($this->getJson('/api/v1/public/articles')->json('meta.total'))->toBe(3);
});

/*
| ⚠️ AN ALLOWLIST, NOT A DENYLIST. Asserting only that no FORBIDDEN key appears
| leaves every allow constant referenced by nothing: a field ADDED to the resource
| is published with no test to notice, and the constants document an intention the
| suite cannot check.
*/
it('publishes only allowlisted fields, in both public payloads', function (): void {
    $workspace = marketplaceWorkspace('Academy');
    marketplaceTeacher($workspace);

    $article = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): Article {
        $category = Category::create([
            'workspace_id' => $workspace->getKey(), 'name' => 'مراجعات', 'slug' => 'reviews',
        ]);
        $tag = Tag::create([
            'workspace_id' => $workspace->getKey(), 'name' => 'ثانوية', 'slug' => 'secondary',
        ]);

        $article = Article::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'category_id' => $category->getKey(),
        ]);
        $article->tags()->sync([$tag->getKey()]);

        return $article;
    });

    $this->asGuest();

    $payloads = [
        'index' => $this->getJson('/api/v1/public/articles')->json(),
        'detail' => $this->getJson('/api/v1/public/articles/'.$article->slug)->json(),
    ];

    $allowed = CmsFieldAllowlist::all();
    $unlisted = [];
    $forbidden = [];

    foreach ($payloads as $label => $payload) {
        foreach (array_unique(cmsPayloadKeys($payload)) as $key) {
            if (! in_array($key, $allowed, true)) {
                $unlisted[] = "{$label}.{$key}";
            }

            // Checked alongside rather than instead: FORBIDDEN is the stronger
            // statement, so a key wrongly written into an allow constant still
            // fails here.
            if (in_array($key, CmsFieldAllowlist::forbidden(), true)) {
                $forbidden[] = "{$label}.{$key}";
            }
        }
    }

    // Collected and asserted once, so a failure names EVERY leaking key rather
    // than the first one and then stopping.
    expect($unlisted)->toBe([], 'published fields that are on no allowlist')
        ->and($forbidden)->toBe([], 'published fields that are forbidden outright');
});

it('links only to teachers the marketplace still lists', function (): void {
    $workspace = marketplaceWorkspace('Academy');
    $listed = marketplaceTeacher($workspace);
    $suspended = marketplaceTeacher($workspace, ['approval_status' => 'suspended']);

    $article = app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::factory()->published()->create(['workspace_id' => $workspace->getKey()]),
    );

    $this->asGuest();

    $uuids = collect($this->getJson('/api/v1/public/articles/'.$article->slug)->json('data.related_teachers'))
        ->pluck('uuid')
        ->all();

    // FR-038 — «يُمنعُ أن تشمل معلَّقاً أو خارجاً عن السوقِ العامّ». The refusal is
    // `publiclyListed()` inside Marketplace's own Action, which is why the rule
    // holds for a teacher suspended after the article was written.
    expect($uuids)->toContain($listed->uuid)
        ->and($uuids)->not->toContain($suspended->uuid);
});

it('carries no related links at all when the workspace left the marketplace', function (): void {
    // Unreachable through the public route — a withdrawn workspace's article
    // 404s — so the Action is asked directly. The alternative failure is worse
    // than empty: an article that is public because ITS workspace opted in,
    // advertising a workspace that did not.
    $withdrawn = marketplaceWorkspace('Withdrawn', participates: false);
    marketplaceTeacher($withdrawn);

    $this->asGuest();

    $links = app(RelatedTeachers::class)
        ->handle((int) $withdrawn->getKey());

    expect($links['teachers'])->toHaveCount(0)
        ->and($links['courses'])->toHaveCount(0);
});

/**
 * Every string key in a nested payload, flattened.
 *
 * @return list<string>
 */
function cmsPayloadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...cmsPayloadKeys($child)];
    }

    return $keys;
}
