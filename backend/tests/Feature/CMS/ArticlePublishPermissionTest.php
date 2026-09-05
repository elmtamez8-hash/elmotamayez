<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `cms.publish` GUARDED A DOOR NOBODY OPENED, AND THEN THERE WAS NO DOOR.
|
| The matrix gives `assistant-teacher` `cms.create` and `cms.update` and withholds
| `cms.publish` and `cms.delete` — a separation somebody made on purpose. Its only
| reader was `ArticlePolicy::publish()` behind a route no client called, while the
| writes took `status` straight out of the payload under `cms.create`/`cms.update`.
| So an assistant published to the teacher's public blog — firing the IndexNow
| ping under their name — and took a live post down again, by sending one field.
|
| That API was deleted on 2026-09-05 as a twin nothing called, which left the four
| CMS permissions with no surface at all: `/admin` admits platform staff alone.
| `/manage/articles` is the teacher's own door, built the same day, and this file
| moved onto it.
|
| ⚠️ EVERY REFUSAL IS PAIRED WITH A POSITIVE CONTROL, because a guard written too
| wide fails in the other direction just as silently: an assistant who cannot fix
| a typo on a published article is an assistant whose `cms.update` means nothing.
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

    $this->postJson('/api/v1/manage/articles', [
        'title' => 'إعلان',
        'status' => 'published',
    ])->assertForbidden();

    expect(Article::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('lets the same assistant create a draft', function (): void {
    [, , $assistant] = cmsPublishFixture();

    Sanctum::actingAs($assistant);

    $this->postJson('/api/v1/manage/articles', ['title' => 'مسوّدة'])
        ->assertCreated()
        // ⚠️ `data.status` and not `status`: a column DEFAULT never reaches the
        // in-memory model, so without the `->fresh()` in the controller this
        // reads `null` about a row that is a perfectly good draft.
        ->assertJsonPath('data.status', 'draft');
});

it('lets an owner holding cms.publish create a published article', function (): void {
    [$workspace, $owner] = cmsPublishFixture();

    app(WorkspaceContext::class)->set($workspace);
    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/manage/articles', [
        'title' => 'إعلان',
        'status' => 'published',
    ])->assertCreated()->assertJsonPath('data.status', 'published');
});

it('refuses an assistant who takes a published article back down', function (): void {
    [$workspace, , $assistant] = cmsPublishFixture();
    $article = cmsArticleIn($workspace);

    Sanctum::actingAs($assistant);

    $this->putJson('/api/v1/manage/articles/'.$article->uuid, [
        'title' => $article->title,
        'status' => 'draft',
    ])->assertForbidden();

    expect($article->fresh()->status)->toBe('published');
});

it('refuses an assistant who moves the date of a live article', function (): void {
    /*
    | ⚠️ THIS CASE INVERTED WHEN THE DOOR MOVED, AND THAT IS THE POINT. On the
    | deleted API the field was not validated at all, so `validated()` dropped it
    | and a permission arm for it would have been a guard nothing could exercise.
    | This screen SCHEDULES, so the date really can arrive — and pushing
    | `published_at` into the future de-lists a live article without `status`
    | moving, because `publicListingConstraints()` asks `published_at <= now()`.
    | Same capability, wearing a date.
    */
    [$workspace, , $assistant] = cmsPublishFixture();
    $article = cmsArticleIn($workspace);

    Sanctum::actingAs($assistant);

    $this->putJson('/api/v1/manage/articles/'.$article->uuid, [
        'title' => $article->title,
        'published_at' => now()->addMonth()->toDateTimeString(),
    ])->assertForbidden();

    expect($article->fresh()->published_at->isPast())->toBeTrue();
});

it('lets an assistant edit the text of a published article', function (): void {
    [$workspace, , $assistant] = cmsPublishFixture();
    $article = cmsArticleIn($workspace);

    // The positive control for both refusals above: `cms.update` still means what
    // it says, and a guard that fails this one has been widened into a ban.
    Sanctum::actingAs($assistant);

    $this->putJson('/api/v1/manage/articles/'.$article->uuid, [
        'title' => 'خطة المراجعة النهائية',
    ])->assertOk();

    expect($article->fresh()->title)->toBe('خطة المراجعة النهائية')
        ->and($article->fresh()->status)->toBe('published');
});

it('lets an assistant edit a published article while echoing its status and date back', function (): void {
    /*
    | ⛔ THE GUARD ASKS WHETHER THE VALUE MOVED, NOT WHETHER IT WAS SENT — and
    | this case is the whole reason. The screen submits the ENTIRE article, so an
    | assistant fixing a typo posts `status: published` and the `published_at` it
    | was given back. A guard on presence refuses them there, which is
    | `cms.update` taken away by the implementation of `cms.publish`.
    |
    | ⚠️ AND THE DATE IS COMPARED AS AN INSTANT, NEVER AS A STRING. The client
    | echoes an ISO-8601 timestamp (`…T…Z`) while the column casts to
    | `Y-m-d H:i:s`: two spellings of one moment, and a string comparison reads
    | every ordinary edit as an attempt to reschedule.
    */
    [$workspace, , $assistant] = cmsPublishFixture();
    $article = cmsArticleIn($workspace);

    Sanctum::actingAs($assistant);

    $this->putJson('/api/v1/manage/articles/'.$article->uuid, [
        'title' => 'خطة المراجعة — تصحيح',
        'status' => 'published',
        'published_at' => $article->published_at->toIso8601String(),
    ])->assertOk();

    expect($article->fresh()->title)->toBe('خطة المراجعة — تصحيح');
});
