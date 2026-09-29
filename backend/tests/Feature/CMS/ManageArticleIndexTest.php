<?php

declare(strict_types=1);

use App\Modules\CMS\Enums\ArticleStatus;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ THE LEAK THE DELETED API SHIPPED FOR FOUR MONTHS, AND THE ONE CONDITION THAT
| CLOSES IT HERE.
|
| `/cms/articles` answered ANY signed-in account. A student is a member of no
| workspace, so `WorkspaceContext::id()` is null, `WorkspaceScope::apply()` adds
| NO condition, and its index returned every workspace's articles — drafts
| included, until the arm that filtered them was written this morning and the
| whole route deleted this afternoon.
|
| `/manage/articles` cannot reach that state, and not because of a second
| predicate: it is gated on `cms.update`, which a student does not hold and
| CANNOT hold without a resolved workspace — spatie is in team mode, and a null
| team id means no roles at all. So the permission and the scope are one
| condition asked twice.
|
| ⚠️ THE GATE IS NOT `cms.view`. Every student holds that one by the matrix; it
| means «may read the blog». A list gated on it would be the same leak with a
| different spelling, which is exactly how the first one happened.
*/

function manageArticleWorkspace(string $title): array
{
    /** @var Workspace $workspace */
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    app(WorkspaceContext::class)->forWorkspace($workspace, fn () => Article::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'title' => $title,
        'status' => 'draft',
        'published_at' => null,
    ]));

    return [$workspace, $owner];
}

it('refuses the list to a student, who holds cms.view and nothing else', function (): void {
    [$workspace] = manageArticleWorkspace('مسوّدة المدرّس');
    $student = test()->addWorkspaceMember($workspace, Roles::STUDENT);

    expect($student->can('cms.view'))->toBeTrue();

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/manage/articles')->assertForbidden();
});

it('shows a teacher their own articles and never another teacher\'s', function (): void {
    [, $first] = manageArticleWorkspace('مسوّدتي');
    [, $second] = manageArticleWorkspace('مسوّدة غيري');

    Sanctum::actingAs($first);

    $body = $this->getJson('/api/v1/manage/articles')->assertOk()->getContent();

    /*
    | ⚠️ RE-ENCODED WITH `JSON_UNESCAPED_UNICODE`. `getContent()` escapes
    | non-ASCII, so `toContain('مسوّدة غيري')` never matches whatever the payload
    | holds — every exposure assertion in this product would pass vacuously
    | against a response that leaked everything.
    */
    $text = json_encode(json_decode($body, true), JSON_UNESCAPED_UNICODE);

    expect($text)->toContain('مسوّدتي')
        ->and($text)->not->toContain('مسوّدة غيري');
});

it('sends the paginator envelope rather than a bare page', function (): void {
    // `response()->json(Resource::collection($paginator))` never calls
    // `toResponse()`: `links` and `meta` are dropped in silence and the list caps
    // at one page with nothing saying there is a second.
    [, $owner] = manageArticleWorkspace('مقال');

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/manage/articles')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);
});

/*
| Server-side paging, search and status (2026-09-29). The list is 15 a page, so a
| filter or a count computed in the browser described page one and read as the
| whole blog.
*/

/** @return list<string> */
function manageArticleTitles(string $query = ''): array
{
    return collect(test()->getJson('/api/v1/manage/articles'.($query === '' ? '' : '?'.$query))
        ->assertOk()->json('data'))->pluck('title')->all();
}

function seedManagedArticles(Workspace $workspace): void
{
    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): void {
        foreach ([
            ['الكسور للمبتدئين', ArticleStatus::Published],
            ['الكسور العشرية', ArticleStatus::Draft],
            ['الهندسة المستوية', ArticleStatus::Published],
        ] as [$title, $status]) {
            Article::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'title' => $title,
                'status' => $status,
                'published_at' => $status === ArticleStatus::Published ? now()->subDay() : null,
            ]);
        }
    });
}

it('pages on the server and reaches the rest of the blog', function (): void {
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    app(WorkspaceContext::class)->forWorkspace($workspace, fn () => Article::factory()->count(17)->create([
        'workspace_id' => $workspace->getKey(),
        'status' => ArticleStatus::Draft,
        'published_at' => null,
    ]));

    Sanctum::actingAs($owner);

    $first = $this->getJson('/api/v1/manage/articles')->assertOk();
    $second = $this->getJson('/api/v1/manage/articles?page=2')->assertOk();

    expect($first->json('meta.total'))->toBe(17)
        ->and($first->json('meta.last_page'))->toBe(2)
        ->and($first->json('data'))->toHaveCount(15)
        ->and($first->json('links.next'))->toContain('page=2')
        ->and($second->json('data'))->toHaveCount(2);
});

it('searches and filters by status on the server, with counts per status', function (): void {
    [$workspace, $owner] = test()->createWorkspaceWithOwner();
    seedManagedArticles($workspace);

    Sanctum::actingAs($owner);

    $all = $this->getJson('/api/v1/manage/articles')->assertOk();
    expect($all->json('meta.counts'))->toBe(['draft' => 1, 'published' => 2]);

    expect(manageArticleTitles('status=published'))
        ->toEqualCanonicalizing(['الكسور للمبتدئين', 'الهندسة المستوية']);

    expect(manageArticleTitles('q='.urlencode('الكسور')))
        ->toEqualCanonicalizing(['الكسور للمبتدئين', 'الكسور العشرية']);

    // The search narrows the counts; the chosen status does not.
    $searched = $this->getJson('/api/v1/manage/articles?status=draft&q='.urlencode('الكسور'))->assertOk();

    expect(collect($searched->json('data'))->pluck('title')->all())->toBe(['الكسور العشرية'])
        ->and($searched->json('meta.total'))->toBe(1)
        ->and($searched->json('meta.counts'))->toBe(['draft' => 1, 'published' => 1]);
});

it('keeps the query string on the page links, so page two is the same search', function (): void {
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    app(WorkspaceContext::class)->forWorkspace($workspace, fn () => Article::factory()->count(16)->create([
        'workspace_id' => $workspace->getKey(),
        'title' => 'مقال مسوّدة',
        'status' => ArticleStatus::Draft,
        'published_at' => null,
    ]));

    Sanctum::actingAs($owner);

    $next = (string) $this->getJson('/api/v1/manage/articles?status=draft')->assertOk()->json('links.next');

    expect($next)->toContain('status=draft')->and($next)->toContain('page=2');
});

it('refuses an unknown status rather than returning the unfiltered list', function (): void {
    [, $owner] = manageArticleWorkspace('مقال');

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/manage/articles?status=archived')->assertUnprocessable();
});

it('never searches or counts another teacher\'s articles', function (): void {
    [$mine, $owner] = test()->createWorkspaceWithOwner();
    [$theirs] = test()->createWorkspaceWithOwner();
    seedManagedArticles($mine);
    seedManagedArticles($theirs);

    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/v1/manage/articles?q='.urlencode('الكسور'))->assertOk();

    expect($response->json('meta.total'))->toBe(2)
        ->and($response->json('meta.counts'))->toBe(['draft' => 1, 'published' => 1]);
});

it('refuses one teacher another teacher\'s article by uuid', function (): void {
    [$other] = manageArticleWorkspace('مسوّدة غيري');
    [, $mine] = manageArticleWorkspace('مسوّدتي');

    $theirs = Article::query()->withoutWorkspaceScope()
        ->where('workspace_id', $other->getKey())->firstOrFail();

    Sanctum::actingAs($mine);

    // 404 and not 403: implicit binding resolves through the workspace scope, so
    // the row is not found rather than found-and-refused — which is the answer
    // that tells the asker nothing about whether it exists.
    $this->getJson('/api/v1/manage/articles/'.$theirs->uuid)->assertNotFound();
    $this->deleteJson('/api/v1/manage/articles/'.$theirs->uuid)->assertNotFound();
});
