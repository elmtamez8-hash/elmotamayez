<?php

declare(strict_types=1);

use App\Modules\CMS\Jobs\PingSearchEnginesJob;
use App\Modules\CMS\Models\Article;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| FR-037 — «النشر أو التحديث يجب أن يُبلَّغ به محرك البحث آلياً».
|
| ⚠️ THE REQUEST SHAPE IS PINNED, AND IT IS A PIN RATHER THAN INDEPENDENT
| CORROBORATION. It is transcribed from the published protocol
| (indexnow.org/documentation, read 2026-08-29): POST, `application/json;
| charset=utf-8`, `{host, key, keyLocation?, urlList}`. Nobody here has an
| IndexNow-enabled property to answer a real submission, so a misread field name
| would be pinned exactly as confidently as a correct one — the same limit
| `BunnyTokenVectorTest` carries and says so about.
|
| What this file DOES prove without a network: that the job is dispatched by both
| write paths, that it is not dispatched for a page a crawler would 404 on, and
| that an unconfigured deployment sends nothing at all rather than earning a 403
| on every publish.
*/

beforeEach(function (): void {
    config()->set('cms.site_url', 'https://example.qa');
    config()->set('cms.indexnow.key', 'a1b2c3d4e5f6a1b2c3d4e5f6');
    config()->set('cms.indexnow.endpoint', 'https://api.indexnow.org/indexnow');
    config()->set('cms.indexnow.key_location', null);
});

/** @param array<string, mixed> $attributes */
function pingArticle(bool $participates = true, array $attributes = []): Article
{
    $workspace = marketplaceWorkspace('Academy '.uniqid(), participates: $participates);

    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::factory()->create([
            'workspace_id' => $workspace->getKey(),
            ...$attributes,
        ]),
    );
}

it('submits the article URL in the shape the protocol asks for', function (): void {
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);

    (new PingSearchEnginesJob(['/blog/خطة-المراجعة']))->handle();

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('POST')
            ->and($request->header('Content-Type'))->toContain('application/json')
            ->and($request['host'])->toBe('example.qa')
            ->and($request['key'])->toBe('a1b2c3d4e5f6a1b2c3d4e5f6')
            // ⚠️ PERCENT-ENCODED PER SEGMENT. The slugs are Arabic, and a raw one
            // in a JSON body is a URL the engine cannot match against what it
            // crawled — nor, on some hops, a legal request line at all.
            ->and($request['urlList'])->toBe(['https://example.qa/blog/'.rawurlencode('خطة-المراجعة')])
            // Omitted, never sent empty. `videos/fetch` cost a day over exactly
            // this: `headers: []` encoded as a JSON array where an object was
            // expected and 400'd every recording on its first attempt.
            ->and($request->data())->not->toHaveKey('keyLocation');

        return true;
    });
});

it('sends nothing at all when no key is configured', function (): void {
    config()->set('cms.indexnow.key', null);

    // ⚠️ `preventStrayRequests`, not a fake with an assertion of zero: the
    // requirement is that no call happens, and a fake would answer one.
    Http::preventStrayRequests();

    (new PingSearchEnginesJob(['/blog/x']))->handle();
})->throwsNoExceptions();

it('does not retry a refusal that a retry cannot change', function (): void {
    // 403 is «the key is not in the file» and 422 is «those URLs are not yours».
    // Both are configuration, so a second attempt spends the rate budget on a
    // request that will be refused identically.
    Http::fake(['api.indexnow.org/*' => Http::response('', 403)]);

    $job = new PingSearchEnginesJob(['/blog/x']);
    $job->handle();

    Http::assertSentCount(1);
});

it('is dispatched when an article is published, and not before', function (): void {
    Queue::fake();

    $article = pingArticle(attributes: ['status' => 'draft', 'published_at' => null]);

    Queue::assertNotPushed(PingSearchEnginesJob::class);

    $article->update(['status' => 'published', 'published_at' => now()]);

    Queue::assertPushed(PingSearchEnginesJob::class, 1);
});

it('announces from the model, so the panel and the API share one spelling', function (): void {
    /*
    | ⚠️ THE DEFECT THIS ASSERTION EXISTS FOR. The announcement started life in
    | `ArticleController::publish()`, which `CmsArticleResource` never touches —
    | a teacher publishing from `/admin` would have notified nobody, silently and
    | for ever. Writing the row directly is what the panel does.
    */
    Queue::fake();

    $article = pingArticle(attributes: ['status' => 'draft', 'published_at' => null]);

    $article->forceFill(['status' => 'published', 'published_at' => now()])->save();

    Queue::assertPushed(PingSearchEnginesJob::class, 1);
});

it('never announces a page a crawler would find a 404 on', function (string $label, array $attributes, bool $participates): void {
    Queue::fake();

    pingArticle(participates: $participates, attributes: $attributes);

    // Inviting a crawler to a page that answers 404 is the one thing that costs a
    // site standing with the engine it just invited.
    Queue::assertNotPushed(PingSearchEnginesJob::class);
})->with([
    'a draft' => ['a draft', ['status' => 'draft', 'published_at' => null], true],
    'scheduled for next week' => ['scheduled', ['status' => 'published', 'published_at' => now()->addWeek()], true],
    'a workspace outside the marketplace' => ['unlisted', ['status' => 'published', 'published_at' => now()], false],
]);
