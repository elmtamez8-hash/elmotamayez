<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages\CreateCmsArticle;
use App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages\EditCmsArticle;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Writing an article from `/admin` — through `SaveArticle`, into the workspace
| the super admin CHOSE.
|
| ⛔ The create page was Filament's `new Article($data)`: `BelongsToWorkspace`
| filled `workspace_id` from the writer's OWN context (their
| `last_workspace_id`), so the article landed on the super admin's blog rather
| than the one they meant; with no workspace at all it was a raw NOT NULL error;
| and `author_id` was never written.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home] = $this->createWorkspaceWithOwner(['name' => 'مساحة المدير']);
    [$this->away] = $this->createWorkspaceWithOwner(['name' => 'مدوّنة المدرّس']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function articlePanelAdmin(?Workspace $home): User
{
    $admin = User::factory()->create(['is_super_admin' => true]);

    if ($home !== null) {
        $admin->forceFill(['last_workspace_id' => $home->getKey()])->save();
    }

    test()->actingAs($admin);
    app()->forgetInstance(WorkspaceContext::class);

    return $admin;
}

it('writes the article into the chosen workspace, with the writer as its author', function (?string $home): void {
    $admin = articlePanelAdmin($home === null ? null : $this->home);

    Livewire::test(CreateCmsArticle::class)
        ->fillForm([
            'workspace' => (string) $this->away->uuid,
            'title' => 'مقالٌ من اللوحة',
            'body' => 'نصّ',
            'status' => 'published',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::query()->withoutWorkspaceScope()->where('title', 'مقالٌ من اللوحة')->firstOrFail();

    expect($article->workspace_id)->toBe($this->away->getKey())
        ->and($article->author_id)->toBe($admin->getKey())
        ->and($article->status)->toBe('published')
        // `publicListingConstraints()` needs a date; a published row without one
        // is invisible to the public.
        ->and($article->published_at)->not->toBeNull();
})->with([
    'a super admin who has a workspace' => ['home'],
    'a super admin with none' => [null],
]);

it('lists and opens another workspace\'s article, and a cleared slug keeps the one it has', function (): void {
    $article = app(WorkspaceContext::class)->forWorkspace(
        $this->away,
        fn (): Article => Article::create([
            'workspace_id' => $this->away->getKey(),
            'title' => 'مقالٌ قديم',
            'body' => 'نصّ',
        ]),
    );
    $slug = $article->refresh()->slug;

    articlePanelAdmin($this->home);

    expect(CmsArticleResource::getEloquentQuery()->whereKey($article->getKey())->exists())->toBeTrue();

    Livewire::test(EditCmsArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['title' => 'مقالٌ مُعدَّل', 'slug' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = Article::query()->withoutWorkspaceScope()->findOrFail($article->getKey());

    expect($stored->title)->toBe('مقالٌ مُعدَّل')
        ->and($stored->slug)->toBe($slug)
        ->and($stored->workspace_id)->toBe($this->away->getKey());
});
