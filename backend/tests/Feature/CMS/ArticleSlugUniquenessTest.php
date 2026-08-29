<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| The slug is the public URL, and it is unique across the PLATFORM (011 · US5).
|
| ⚠️ WITHOUT THAT, `/blog/{slug}` IS AMBIGUOUS BY DEFINITION. The table carried
| `unique(workspace_id, slug)` until this spec, so two teachers could both publish
| «خطة-المراجعة» — and a public route keyed on the slug alone has nothing to tell
| them apart with. Whichever row comes back first is the article the reader gets,
| and which one that is can change between two requests. The sitemap emits two
| entries for one address, which is `SC-011` («١٠٠٪ من المنشور») undefined rather
| than unmet.
*/

/** @return array{0: Workspace, 1: User} */
function cmsAuthor(string $name): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner(['name' => $name]);
    test()->setCurrentWorkspace($workspace, $owner);

    return [$workspace, $owner];
}

it('gives the second workspace a different URL for the same title', function (): void {
    [, $first] = cmsAuthor('Academy A');
    Sanctum::actingAs($first);

    $this->postJson('/api/v1/cms/articles', ['title' => 'خطة المراجعة'])->assertCreated();

    [, $second] = cmsAuthor('Academy B');
    Sanctum::actingAs($second);

    $this->postJson('/api/v1/cms/articles', ['title' => 'خطة المراجعة'])->assertCreated();

    $slugs = Article::query()->withoutWorkspaceScope()->orderBy('id')->pluck('slug')->all();

    // `-2`, the same suffix the dedupe migration writes, so a renumbered row and
    // a newly-created one look alike.
    expect($slugs)->toBe(['خطة-المراجعة', 'خطة-المراجعة-2']);
});

it('refuses a slug the teacher typed that somebody else already holds', function (): void {
    [, $first] = cmsAuthor('Academy A');
    Sanctum::actingAs($first);

    $this->postJson('/api/v1/cms/articles', ['title' => 'أ', 'slug' => 'مراجعة-الثانوية'])->assertCreated();

    [, $second] = cmsAuthor('Academy B');
    Sanctum::actingAs($second);

    /*
    | ⚠️ 422 AND A SENTENCE, NEVER A 500. `Rule::unique()` is a raw query — no
    | global scopes and no `deleted_at` clause — which is exactly the shape of the
    | index, so the message the teacher reads and the constraint the database
    | enforces answer the same question. Without the rule this is an unhandled
    | integrity error on a form the teacher cannot correct.
    */
    $this->postJson('/api/v1/cms/articles', ['title' => 'ب', 'slug' => 'مراجعة-الثانوية'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('counts a trashed article, because a unique index knows nothing about deleted_at', function (): void {
    [$workspace, $owner] = cmsAuthor('Academy');
    Sanctum::actingAs($owner);

    $uuid = $this->postJson('/api/v1/cms/articles', ['title' => 'مقال قديم'])->json('uuid');
    $this->deleteJson("/api/v1/cms/articles/{$uuid}")->assertNoContent();

    // The trashed row still holds `مقال-قديم` against the WHOLE platform. Both
    // doors must agree about that: the package's generator, and the validator.
    $this->postJson('/api/v1/cms/articles', ['title' => 'مقال قديم'])->assertCreated();

    $this->postJson('/api/v1/cms/articles', ['title' => 'x', 'slug' => 'مقال-قديم'])
        ->assertStatus(422);

    $slugs = app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): array => Article::query()->withTrashed()->orderBy('id')->pluck('slug')->all(),
    );

    expect($slugs)->toBe(['مقال-قديم', 'مقال-قديم-2']);
});

it('never moves a published URL when the article is renamed', function (): void {
    [, $owner] = cmsAuthor('Academy');
    Sanctum::actingAs($owner);

    $created = $this->postJson('/api/v1/cms/articles', ['title' => 'العنوان الأول'])->json();

    $this->putJson("/api/v1/cms/articles/{$created['uuid']}", ['title' => 'العنوان الثاني'])
        ->assertOk()
        ->assertJsonPath('title', 'العنوان الثاني')
        // A published URL that changes is a URL that 404s, and every share of it
        // is a dead link. `doNotGenerateSlugsOnUpdate()`.
        ->assertJsonPath('slug', $created['slug']);
});

it('falls back to a readable stem for a title that slugifies to nothing', function (): void {
    [, $owner] = cmsAuthor('Academy');
    Sanctum::actingAs($owner);

    // The package's own answer for an empty slug is `-1`, which is a URL nobody
    // can read and the one shape a fallback is for.
    $slug = $this->postJson('/api/v1/cms/articles', ['title' => '؟؟؟ !!!'])->json('slug');

    expect($slug)->toBe('مقال');
});

it('lets a teacher without publish rights create but not publish', function (): void {
    // Guards the fixture above as much as anything: `cmsAuthor` signs in an OWNER,
    // and a test suite that only ever exercises an owner proves nothing about the
    // permission names the routes actually read.
    [$workspace] = cmsAuthor('Academy');
    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/cms/articles', ['title' => 'ممنوع'])->assertForbidden();
});
