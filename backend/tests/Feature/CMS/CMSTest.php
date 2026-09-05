<?php

declare(strict_types=1);

use App\Modules\CMS\Models\Article;
use App\Modules\CMS\Models\Category;
use App\Modules\CMS\Models\Tag;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

describe('cms articles', function (): void {
    it('allows a teacher to create and publish an article', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $createResp = $this->postJson('/api/v1/cms/articles', [
            'title' => 'Getting Started',
            'body' => 'Welcome to the platform.',
            'seo_title' => 'Getting Started Guide',
            'seo_description' => 'A guide for new users.',
        ])->assertCreated();

        $uuid = $createResp->json('uuid');

        $this->postJson("/api/v1/cms/articles/{$uuid}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');

        expect(Article::where('uuid', $uuid)->first()->isPublished())->toBeTrue()
            ->and(Article::where('uuid', $uuid)->first()->seo_title)->toBe('Getting Started Guide');
    });

    it('lists published articles for students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();

        Article::create([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'title' => 'Published Article',
            'slug' => 'published-article',
            'body' => 'Content',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Article::create([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'title' => 'Draft Article',
            'slug' => 'draft-article',
            'body' => 'Content',
            'status' => 'draft',
        ]);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        /*
        | ⚠️ `data.0`, NOT `0` — the shape changed on 2026-09-05 and the change is
        | the fix. `response()->json(Resource::collection($paginator))` never calls
        | `toResponse()`, so it emitted a BARE ARRAY: `links` and `meta` dropped in
        | silence, and every reader stuck on page one with nothing saying there is
        | a page two. No client broke, because no file under `frontend/src` calls
        | this route at all.
        */
        $this->getJson('/api/v1/cms/articles')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Published Article')
            ->assertJsonMissing(['title' => 'Draft Article']);
    });

    it('creates an article with category and tags', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        $category = Category::create([
            'workspace_id' => $workspace->id,
            'name' => 'Tutorials',
            'slug' => 'tutorials',
        ]);

        $tag = Tag::create([
            'workspace_id' => $workspace->id,
            'name' => 'Beginner',
            'slug' => 'beginner',
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/cms/articles', [
            'title' => 'How to Learn',
            'body' => 'Step by step guide.',
            'category_id' => $category->id,
            'tag_ids' => [$tag->id],
        ])->assertCreated();

        $article = Article::where('title', 'How to Learn')->first();
        expect($article->category_id)->toBe($category->id)
            ->and($article->tags->count())->toBe(1)
            ->and($article->tags->first()->name)->toBe('Beginner');
    });

    it('prevents a student from creating articles', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');

        Sanctum::actingAs($student);

        $this->postJson('/api/v1/cms/articles', [
            'title' => 'Unauthorized',
            'body' => 'Should fail.',
        ])->assertForbidden();
    });

    it('prevents cross-workspace article access', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner();
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner();

        $article = Article::create([
            'workspace_id' => $workspaceA->id,
            'uuid' => Str::uuid(),
            'title' => 'A Article',
            'slug' => 'a-article',
            'body' => 'Content',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Sanctum::actingAs($ownerB);

        $this->getJson("/api/v1/cms/articles/{$article->uuid}")
            ->assertNotFound();
    });

    it('updates an article', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $article = Article::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'Old Title', 'slug' => 'old-title', 'body' => 'Old', 'status' => 'draft',
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/cms/articles/{$article->uuid}", [
            'title' => 'New Title', 'body' => 'Updated body',
        ])->assertOk()
            ->assertJsonPath('title', 'New Title')
            ->assertJsonPath('body', 'Updated body');
    });

    it('deletes an article', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $article = Article::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'To Delete', 'slug' => 'to-delete', 'body' => 'x', 'status' => 'draft',
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/cms/articles/{$article->uuid}")->assertNoContent();
        expect(Article::where('id', $article->id)->exists())->toBeFalse();
    });

    it('denies article deletion to students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        $article = Article::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'title' => 'X', 'slug' => 'x', 'body' => 'x', 'status' => 'draft',
        ]);

        Sanctum::actingAs($student);

        $this->deleteJson("/api/v1/cms/articles/{$article->uuid}")->assertForbidden();
    });
});
