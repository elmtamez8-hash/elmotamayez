<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `cms.publish` GUARDED A DOOR NOBODY OPENED.
|
| The matrix gives `assistant-teacher` `cms.create` and `cms.update` and withholds
| `cms.publish` and `cms.delete` — a separation somebody made on purpose. Its only
| reader in the tree was `ArticlePolicy::publish()`, reached from
| `POST /cms/articles/{article}/publish`, which no file under `frontend/src` calls.
| Meanwhile `store()` and `update()` took `status` straight out of the payload
| under `cms.create`/`cms.update`, and the Filament form offered the same select
| with no gate at all.
|
| So an assistant published to the teacher's public blog — firing the IndexNow
| ping under their name — and took a live post down again, by sending one field.
|
| ⚠️ EVERY REFUSAL CASE HERE IS PAIRED WITH A POSITIVE CONTROL, because a guard
| written too wide fails in the other direction just as silently: an assistant who
| cannot fix a typo on a published article is an assistant whose `cms.update` means
| nothing. `it('lets an assistant edit …')` is what fails if the condition is
| widened to «any write touching a published article».
*/

/** @return array{0: Workspace, 1: User, 2: User} */
function cmsPublishFixture(): array
{
    /** @var Workspace $workspace */
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    // tenant-owner is built with array_merge($teacher, …), so it HOLDS
    // `cms.publish` — which is what makes it the positive control below.
    $assistant = test()->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    return [$workspace, $owner, $assistant];
}

function cmsArticleIn(Workspace $workspace, bool $published = true): Article
{
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'title' => 'خطة المراجعة',
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now()->subDay() : null,
        ]),
    );
}

it('refuses an assistant who creates an article already published', function (): void {
    [, , $assistant] = cmsPublishFixture();

    Sanctum::actingAs($assistant);

    $this->postJson('/api/v1/cms/articles', [
        'title' => 'إعلان',
        'status' => 'published',
    ])->assertForbidden();

    expect(Article::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('lets the same assistant create a draft', function (): void {
    [, , $assistant] = cmsPublishFixture();

    Sanctum::actingAs($assistant);

    $this->postJson('/api/v1/cms/articles', ['title' => 'مسوّدة'])
        ->assertCreated()
        ->assertJsonPath('status', 'draft');
});

it('lets an owner holding cms.publish create a published article', function (): void {
    [$workspace, $owner] = cmsPublishFixture();

    app(WorkspaceContext::class)->set($workspace);
    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/cms/articles', [
        'title' => 'إعلان',
        'status' => 'published',
    ])->assertCreated()->assertJsonPath('status', 'published');
});

it('refuses an assistant who takes a published article back down', function (): void {
    [$workspace, , $assistant] = cmsPublishFixture();
    $article = cmsArticleIn($workspace);

    Sanctum::actingAs($assistant);

    $this->putJson('/api/v1/cms/articles/'.$article->uuid, [
        'title' => $article->title,
        'status' => 'draft',
    ])->assertForbidden();

    expect($article->fresh()->status)->toBe('published');
});

it('cannot move the date of a live article at all through this door', function (): void {
    [$workspace, , $assistant] = cmsPublishFixture();
    $article = cmsArticleIn($workspace);

    /*
    | Moving `published_at` into the future de-lists a live article without
    | touching `status` — `publicListingConstraints()` asks `published_at <= now()`
    | — so it is the same capability wearing a date. On THIS door it is closed by
    | `CreateArticleRequest`, which does not validate the field, so `validated()`
    | drops it in silence. The panel is where the date is editable, and the field
    | is disabled there under `cms.publish`.
    |
    | Written down because the obvious hardening — a `published_at` arm in the
    | permission check here — would be a condition for a key that cannot arrive:
    | a guard nothing exercises, which is what this batch exists to remove.
    */
    Sanctum::actingAs($assistant);

    $this->putJson('/api/v1/cms/articles/'.$article->uuid, [
        'title' => $article->title,
        'published_at' => now()->addMonth()->toDateTimeString(),
    ])->assertOk();

    expect($article->fresh()->published_at->isPast())->toBeTrue();
});

it('lets an assistant edit the text of a published article', function (): void {
    [$workspace, , $assistant] = cmsPublishFixture();
    $article = cmsArticleIn($workspace);

    // The positive control for both refusals above: `cms.update` still means what
    // it says, and a guard that fails this one has been widened into a ban.
    Sanctum::actingAs($assistant);

    $this->putJson('/api/v1/cms/articles/'.$article->uuid, [
        'title' => 'خطة المراجعة النهائية',
    ])->assertOk();

    expect($article->fresh()->title)->toBe('خطة المراجعة النهائية')
        ->and($article->fresh()->status)->toBe('published');
});
