<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/*
| ⛔ THE PANEL IS THE DOOR PEOPLE ACTUALLY USE, AND IT HAD NO GATE ON THESE TWO
| FIELDS.
|
| No file under `frontend/src` calls any `/cms/articles` path, so the API guard in
| `ArticleController` closes a door nobody was walking through. `CmsArticleResource`
| is the surface: `canEdit()` asks `cms.update`, which the matrix gives an
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
