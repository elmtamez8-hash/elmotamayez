<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `GET /cms/articles` ANSWERED ANY SIGNED-IN ACCOUNT ABOUT EVERY WORKSPACE.
|
| The non-staff branch was `status = published` AND `published_at IS NOT NULL` —
| two conditions about the ROW and none about the reader. `WorkspaceScope::apply()`
| adds no condition when the context is null, and the context is ALWAYS null for a
| student: nothing on their path writes `users.last_workspace_id`. So the list was
| cross-tenant, and it carried the two families the public blog deliberately
| withholds — a workspace that opted OUT of the marketplace, and an article
| SCHEDULED for a date that has not arrived. `ArticlePolicy::view()` had the same
| shape, so `show()` opened each one by uuid.
|
| ⚠️ THE FIXTURE MUST LEAVE `last_workspace_id` NULL AND RESET THE CONTEXT, or it
| measures a person production never creates: `Sanctum::actingAs()` alone leaves
| whatever resolution an earlier line froze, and any reader with a context takes
| the branch that was never broken.
|
| ⚠️ AND THE SENTINEL IS ASCII. `TestResponse::getContent()` escapes non-ASCII, so
| a `not->toContain('عنوان')` assertion is vacuously true whatever the payload
| holds. The titles here are English on purpose — the same precedent as
| `CMS_DRAFT_SENTINEL` one file away.
*/

function articleForReader(bool $participates, ?string $publishedAt, string $title): Article
{
    $workspace = marketplaceWorkspace('Academy '.uniqid(), participates: $participates);

    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'title' => $title,
            'status' => 'published',
            'published_at' => $publishedAt,
        ]),
    );
}

/** The context production gives a student: none. */
function actAsStudent(): User
{
    $student = User::factory()->create();

    expect($student->last_workspace_id)->toBeNull();

    Sanctum::actingAs($student);
    test()->asGuest();

    return $student;
}

it('shows a student only articles the public blog would show them', function (): void {
    $listed = articleForReader(true, now()->subDay()->toDateTimeString(), 'LISTED-ARTICLE');
    $unlisted = articleForReader(false, now()->subDay()->toDateTimeString(), 'OPTED-OUT-ARTICLE');
    $scheduled = articleForReader(true, now()->addMonth()->toDateTimeString(), 'SCHEDULED-ARTICLE');

    actAsStudent();

    $body = $this->getJson('/api/v1/cms/articles')->assertOk()->getContent();

    // The positive control is the other half of the same assertion: dropping every
    // row would satisfy both negations and take the endpoint off the platform.
    expect($body)->toContain($listed->title)
        ->and($body)->not->toContain($unlisted->title)
        ->and($body)->not->toContain($scheduled->title);
});

it('refuses a student the article of a workspace that left the marketplace', function (): void {
    $unlisted = articleForReader(false, now()->subDay()->toDateTimeString(), 'OPTED-OUT-ARTICLE');

    actAsStudent();

    $this->getJson('/api/v1/cms/articles/'.$unlisted->uuid)->assertForbidden();
});

it('refuses a student an article scheduled for a date that has not arrived', function (): void {
    $scheduled = articleForReader(true, now()->addMonth()->toDateTimeString(), 'SCHEDULED-ARTICLE');

    actAsStudent();

    $this->getJson('/api/v1/cms/articles/'.$scheduled->uuid)->assertForbidden();
});

it('still opens a publicly listed article for a student', function (): void {
    $listed = articleForReader(true, now()->subDay()->toDateTimeString(), 'LISTED-ARTICLE');

    // The positive control for the two refusals above.
    actAsStudent();

    $this->getJson('/api/v1/cms/articles/'.$listed->uuid)
        ->assertOk()
        ->assertJsonPath('title', 'LISTED-ARTICLE');
});

it('sends the paginator envelope rather than a bare page', function (): void {
    // `response()->json(Resource::collection($paginator))` never calls
    // `toResponse()`, so `links` and `meta` are dropped in silence and every
    // client is stuck on page one with no way to know there is a page two.
    articleForReader(true, now()->subDay()->toDateTimeString(), 'LISTED-ARTICLE');

    actAsStudent();

    $this->getJson('/api/v1/cms/articles')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('keeps the teacher reading their own workspace as before', function (): void {
    /** @var Workspace $workspace */
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $workspace->forceFill(['participates_in_marketplace' => false])->save();

    $draft = app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Article => Article::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'title' => 'OWN-DRAFT',
            'status' => 'draft',
        ]),
    );

    /*
    | ⚠️ THE STAFF BRANCH IS UNTOUCHED ON PURPOSE, AND THIS IS WHAT SAYS SO. A
    | teacher whose workspace left the marketplace must still see their own drafts
    | and their own published articles — narrowing them by public listing would be
    | an entitlement change wearing a security fix's clothes, which is the mirror
    | of the defect above.
    */
    app(WorkspaceContext::class)->set($workspace);
    Sanctum::actingAs($owner);

    expect($this->getJson('/api/v1/cms/articles')->assertOk()->getContent())
        ->toContain($draft->title);

    $this->getJson('/api/v1/cms/articles/'.$draft->uuid)->assertOk();
});
