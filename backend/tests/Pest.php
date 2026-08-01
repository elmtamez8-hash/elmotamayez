<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithWorkspace;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

uses(WithWorkspace::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Marketplace helpers
|--------------------------------------------------------------------------
|
| Shared by every Feature/Marketplace test. They live here rather than in one
| test file because a global function declared inside a test file is only
| available to the files Pest happens to load after it.
|
*/

function marketplaceWorkspace(string $name = 'Academy', bool $participates = true): Workspace
{
    /** @var Workspace $workspace */
    [$workspace] = test()->createWorkspaceWithOwner(['name' => $name]);

    $workspace->forceFill(['participates_in_marketplace' => $participates])->save();

    return $workspace;
}

/** @param array<string, mixed> $attrs */
function marketplaceTeacher(Workspace $workspace, array $attrs = []): TeacherProfile
{
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn () => TeacherProfile::factory()
            ->published()
            ->scored()
            ->create([...$attrs, 'workspace_id' => $workspace->getKey()]),
    );
}
