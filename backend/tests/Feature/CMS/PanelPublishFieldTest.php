<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/*
| ⛔ THE PANEL IS THE DOOR PEOPLE ACTUALLY USE, AND IT HAD NO GATE ON THESE TWO
| FIELDS.
|
| No file under `frontend/src` ever called a `/cms/articles` path, so the API guard
| written beside this one closed a door nobody was walking through — and those six
| routes were deleted on 2026-09-05, replaced by `/manage/articles`, which is the
| TEACHER's door while this Resource is the platform's. Both carry the gate, and
| `ArticlePublishPermissionTest` is this file's other half.
|
| `canEdit()` asks `cms.update`, which the matrix gives an
| assistant-teacher — while `cms.publish` is the teacher's alone — and the status
| select carried no `disabled()`, `visible()` or `dehydrated()` of any kind.
|
| ⚠️ IT ASSERTS BOTH DIRECTIONS. A field disabled for everybody is a teacher who
| cannot publish their own blog, which fails silently in exactly the same way.
|
| ⚠️ AND `published_at` IS THE SAME CAPABILITY WEARING A DATE — a value in the
| future de-lists a live article without `status` moving at all, because
| `publicListingConstraints()` asks `published_at <= now()`. Unlike the API, this
| door really can write it.
*/

function articleFormField(string $name): Component
{
    $found = null;

    $walk = function (array $components) use (&$walk, &$found, $name): void {
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && $component->getName() === $name) {
                $found = $component;

                return;
            }

            if (method_exists($component, 'getDefaultChildComponents')) {
                $walk($component->getDefaultChildComponents());
            }
        }
    };

    $schema = CmsArticleResource::form(Schema::make());

    $walk($schema->getComponents());

    expect($found)->not->toBeNull("the form has no «{$name}» field any more");

    // `isDisabled()` falls through to the parent container when the field's own
    // answer is false, and a component pulled out of `getDefaultChildComponents()`
    // has none — so the FALSE branch (the positive control) throws rather than
    // answering, and only the true branch would ever be measured.
    return $found->container($schema);
}

it('disables publishing for a writer who may not publish', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    Auth::login($assistant);

    expect(articleFormField('status')->isDisabled())->toBeTrue()
        ->and(articleFormField('published_at')->isDisabled())->toBeTrue();
});

it('leaves both fields open for the owner, who holds cms.publish', function (): void {
    // The positive control: `tenant-owner` is built with `array_merge($teacher, …)`
    // and therefore holds `cms.publish`. Without this case a field disabled for
    // everybody would pass the assertion above.
    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    app(WorkspaceContext::class)->set($workspace);
    Auth::login($owner);

    expect(articleFormField('status')->isDisabled())->toBeFalse()
        ->and(articleFormField('published_at')->isDisabled())->toBeFalse();
});

it('keeps the fields shut when nobody is signed in', function (): void {
    // A panel screen is never reached anonymously; this is here because the
    // predicate is `can()` on a nullable user, and the direction a permission
    // check must fail in is closed.
    expect(User::query()->count())->toBe(0);

    expect(articleFormField('status')->isDisabled())->toBeTrue();
});

/*
| ⛔ THE SCREEN'S OWN DOOR IS THE SUPER ADMIN, AND NO `cms.*` PERMISSION OPENS IT.
|
| This case used to assert the opposite — the owner opening the screen through
| `cms.view`/`cms.create`/`cms.update`, the assistant stopped at `cms.delete` —
| and argued that was safe because `mayAccessAdminPanel()` keeps every workspace
| role out of the panel. It keeps out the ROLES, not the PEOPLE: a finance or
| compliance officer who also owns a workspace passes the panel's door AND holds
| all four permissions in their own workspace. With the list platform-wide (it
| showed the super admin their own blog as the platform's), a `cms.*` door would
| hand that officer every teacher's drafts. The teacher's own screen is
| `/manage/articles`, and `cms.create`/`cms.update`/`cms.delete` are measured
| there, by `ArticlePublishPermissionTest` and `ManageArticleIndexTest`.
*/
it('opens the screen to the super admin and to nobody who holds cms.* in a workspace', function (string $who): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $article = Article::create([
        'workspace_id' => $workspace->getKey(),
        'title' => 'مقال',
        'body' => 'نصّ',
    ]);

    $user = match ($who) {
        'owner' => $owner,
        'assistant' => $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER),
        'officer who owns the workspace' => makePlatformStaff(Roles::FINANCE_ADMIN, $owner),
    };

    app(WorkspaceContext::class)->set($workspace);
    Auth::login($user);

    expect($user->can(Permissions::CMS_VIEW))->toBeTrue()
        ->and(CmsArticleResource::canViewAny())->toBeFalse()
        ->and(CmsArticleResource::canCreate())->toBeFalse()
        ->and(CmsArticleResource::canEdit($article))->toBeFalse()
        ->and(CmsArticleResource::canDelete($article))->toBeFalse();

    Auth::login(User::factory()->create(['is_super_admin' => true]));

    expect(CmsArticleResource::canViewAny())->toBeTrue()
        ->and(CmsArticleResource::canEdit($article))->toBeTrue();
})->with(['owner', 'assistant', 'officer who owns the workspace']);
