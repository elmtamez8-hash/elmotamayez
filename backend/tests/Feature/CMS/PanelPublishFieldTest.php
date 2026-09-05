<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use App\Modules\CMS\Models\Article;
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
| ⛔ AND THE THREE ORDINARY GATES ARE MEASURED HERE, because after the API went
| this Resource is the only thing that reads `cms.create`, `cms.update` and
| `cms.delete` at all. `CMSTest` asked them of the routes — a student refused a
| write, a student refused a delete — and a claim proved only against a deleted
| door is a claim nobody is making any more.
|
| ⚠️ THE ASSISTANT IS THE CASE THAT MATTERS. The matrix gives them `cms.update`
| and withholds `cms.delete`, so «may edit» and «may delete» must come apart in
| the same fixture; a test that only ever asks an owner and a student passes
| against a Resource that reads one permission for both.
|
| ⚠️ AND `canViewAny()` IS TRUE FOR A STUDENT, WHICH IS NOT A HOLE HERE AND WOULD
| BE ONE ELSEWHERE. `cms.view` is «may read the blog» and every student holds it
| by the matrix. What keeps them out of THIS screen is not a Resource method at
| all — it is `mayAccessAdminPanel()`, which admits a super admin and
| `platform_staff` and NOBODY holding a workspace role, asserted below. The API
| has no such door in front of it, which is why `/manage/articles` is gated on
| `cms.update` instead and `ArticlePolicy::viewAny()` says so in writing. These per-ability gates are
| the second lock on a door the first one already shuts; both are wanted, and a
| test that pretended the first one was `canViewAny()` would be measuring the
| wrong thing and would break the day the panel is widened again.
*/
it('offers the panel by permission and not by role name', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $article = Article::create([
        'workspace_id' => $workspace->getKey(),
        'title' => 'مقال',
        'body' => 'نصّ',
    ]);

    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);
    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    app(WorkspaceContext::class)->set($workspace);

    Auth::login($owner);
    expect(CmsArticleResource::canViewAny())->toBeTrue()
        ->and(CmsArticleResource::canCreate())->toBeTrue()
        ->and(CmsArticleResource::canEdit($article))->toBeTrue()
        ->and(CmsArticleResource::canDelete($article))->toBeTrue();

    Auth::login($assistant);
    expect(CmsArticleResource::canCreate())->toBeTrue()
        ->and(CmsArticleResource::canEdit($article))->toBeTrue()
        // `cms.delete` is the teacher's alone — taking a post off the blog for
        // good is not the same act as fixing a paragraph in it.
        ->and(CmsArticleResource::canDelete($article))->toBeFalse();

    Auth::login($student);
    expect(CmsArticleResource::canCreate())->toBeFalse()
        ->and(CmsArticleResource::canEdit($article))->toBeFalse()
        ->and(CmsArticleResource::canDelete($article))->toBeFalse();

    // The door itself, and the reason the three sets above are a second lock.
    expect($owner->mayAccessAdminPanel())->toBeFalse()
        ->and($assistant->mayAccessAdminPanel())->toBeFalse()
        ->and($student->mayAccessAdminPanel())->toBeFalse();
});
