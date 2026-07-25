<?php

declare(strict_types=1);

namespace App\Modules\CMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CMS\Http\Requests\CreateArticleRequest;
use App\Modules\CMS\Http\Resources\ArticleResource;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Article::query();

        // Only staff (those who can create/update articles) see drafts; everyone else sees published only.
        if (! $this->currentUser($request)->can(Permissions::CMS_CREATE)) {
            $query->where('status', 'published')->whereNotNull('published_at');
        }

        $articles = $query->with(['category', 'tags'])->orderByDesc('created_at')->paginate(15);

        return response()->json(ArticleResource::collection($articles));
    }

    public function show(Article $article): JsonResponse
    {
        $this->authorize('view', $article);

        return response()->json(ArticleResource::make($article->load(['category', 'tags'])));
    }

    public function store(CreateArticleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] ??= Str::slug($data['title'].'-'.Str::random(6));
        $data['author_id'] = $this->currentUser($request)->getKey();

        $article = Article::create($data);

        if (isset($data['tag_ids'])) {
            $article->tags()->sync($data['tag_ids']);
        }

        return response()->json(ArticleResource::make($article), 201);
    }

    public function update(CreateArticleRequest $request, Article $article): JsonResponse
    {
        $this->authorize('update', $article);

        $article->update($request->validated());

        if (isset($request->validated()['tag_ids'])) {
            $article->tags()->sync($request->validated()['tag_ids']);
        }

        return response()->json(ArticleResource::make($article->fresh()));
    }

    public function publish(Request $request, Article $article): JsonResponse
    {
        $this->authorize('publish', $article);

        $article->update([
            'status' => 'published',
            'published_at' => $article->published_at ?? now(),
        ]);

        return response()->json(ArticleResource::make($article->fresh()));
    }

    public function destroy(Request $request, Article $article): JsonResponse
    {
        $this->authorize('delete', $article);

        $article->delete();

        return response()->json(null, 204);
    }
}
