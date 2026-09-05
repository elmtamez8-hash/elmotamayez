<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages\CreateCmsArticle;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

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
|
| ⚠️ THIS FILE USED TO DRIVE `POST /api/v1/cms/articles`, DELETED 2026-09-05.
| Nothing about the rules moved with it: generation is spatie's `HasSlug` on the
| MODEL, so every writer gets it — which is why these cases now write the model
| directly, the same way the panel does. The one thing the API carried alone was
| the refusal of a slug the teacher TYPED, and that rule moved onto the panel's
| own field; the case below is asked at that door instead.
*/

/** @return array{0: Workspace, 1: User} */
function cmsAuthor(string $name): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner(['name' => $name]);
    test()->setCurrentWorkspace($workspace, $owner);

    return [$workspace, $owner];
}

/** Create an article as the panel does: through the model, inside a workspace. */
function cmsArticle(Workspace $workspace, array $attributes): Article
{
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::create($attributes),
    );
}

it('gives the second workspace a different URL for the same title', function (): void {
    [$first] = cmsAuthor('Academy A');
    cmsArticle($first, ['title' => 'خطة المراجعة']);

    [$second] = cmsAuthor('Academy B');
    cmsArticle($second, ['title' => 'خطة المراجعة']);

    $slugs = Article::query()->withoutWorkspaceScope()->orderBy('id')->pluck('slug')->all();

    // `-2`, the same suffix the dedupe migration writes, so a renumbered row and
    // a newly-created one look alike.
    expect($slugs)->toBe(['خطة-المراجعة', 'خطة-المراجعة-2']);
});

it('refuses a slug the teacher typed that somebody else already holds', function (): void {
    /*
    | ⚠️ A SENTENCE UNDER THE FIELD, NEVER AN INTEGRITY ERROR. The rule is a raw
    | `Rule::unique()` — no global scope and no `deleted_at` clause — which is
    | exactly the shape of the index, so the message the teacher reads and the
    | constraint the database enforces answer the same question. It lived on
    | `CreateArticleRequest` until the API was deleted; the field carried none of
    | its own, so this case is what says the move actually happened.
    */
    [$first] = cmsAuthor('Academy A');
    cmsArticle($first, ['title' => 'أ', 'slug' => 'مراجعة-الثانوية']);

    [, $second] = cmsAuthor('Academy B');
    Auth::login($second);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(CreateCmsArticle::class)
        ->fillForm(['title' => 'ب', 'slug' => 'مراجعة-الثانوية'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('counts a trashed article, because a unique index knows nothing about deleted_at', function (): void {
    [$workspace] = cmsAuthor('Academy');

    cmsArticle($workspace, ['title' => 'مقال قديم'])->delete();

    // The trashed row still holds `مقال-قديم` against the WHOLE platform, so the
    // generator must step over it rather than collide with it.
    cmsArticle($workspace, ['title' => 'مقال قديم']);

    $slugs = app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): array => Article::query()->withTrashed()->orderBy('id')->pluck('slug')->all(),
    );

    expect($slugs)->toBe(['مقال-قديم', 'مقال-قديم-2']);
});

it('never moves a published URL when the article is renamed', function (): void {
    [$workspace] = cmsAuthor('Academy');

    $article = cmsArticle($workspace, ['title' => 'العنوان الأول']);
    $was = $article->slug;

    $article->update(['title' => 'العنوان الثاني']);

    // A published URL that changes is a URL that 404s, and every share of it is a
    // dead link. `doNotGenerateSlugsOnUpdate()`.
    expect($article->refresh()->title)->toBe('العنوان الثاني')
        ->and($article->slug)->toBe($was);
});

it('falls back to a readable stem for a title that slugifies to nothing', function (): void {
    [$workspace] = cmsAuthor('Academy');

    // The package's own answer for an empty slug is `-1`, which is a URL nobody
    // can read and the one shape a fallback is for.
    expect(cmsArticle($workspace, ['title' => '؟؟؟ !!!'])->slug)->toBe('مقال');
});
