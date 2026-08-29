<?php

declare(strict_types=1);

use App\Modules\CMS\Http\Controllers\ArticleController;
use App\Modules\CMS\Http\Controllers\PublicArticleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public blog (no authentication) — 011 · US5 · FR-032 · FR-039
|--------------------------------------------------------------------------
|
| `WorkspaceScope` filters nothing here: it returns early when there is no
| authenticated user, so these two routes have no tenant isolation at all. The
| replacement guard is `publiclyListed()` inside each Action, and it is the
| difference between publishing the blog and publishing every workspace's drafts.
|
| ⚠️ THE SLUG IS A PLAIN STRING. `Route::get('/public/articles/{article}')` binds
| the model by uuid without the guard — the one line that undoes all of it.
|
| `throttle:public` by IP, because there is no actor to key on. Named, never
| inline: `ThrottleRequests` keys guests on `domain|ip` with no route in the hash,
| so every inline limit shares one counter and the strictest wins — browsing the
| marketplace used to lock a visitor out of logging in.
*/
Route::middleware('throttle:public')->group(function (): void {
    Route::get('/public/articles', [PublicArticleController::class, 'index']);
    // No `->where()` on the slug: it is Arabic and arrives percent-encoded, so
    // the default `[^/]+` matches it and an `[a-z0-9-]+` pattern — the reflex
    // when a segment is called «slug» — would 404 every article on the platform.
    Route::get('/public/articles/{slug}', [PublicArticleController::class, 'show']);
});

/*
| ⚠️ THESE SIX SHIPPED WITH `auth:sanctum` AND NOTHING ELSE, AND FOUR OF THEM ARE
| WRITES. NFR-014 has required a named limiter on every write path since spec 011
| put it in writing, and the module predates it by four months — so the absence
| read as "internal, nobody calls it", which was true right up until spec 011
| gave the blog a public reader and an authoring screen.
|
| `authoring` rather than a limiter of its own: writing an article is the same
| kind of work as building a lesson — a teacher saves dozens of times in an hour,
| and a limit that interrupts that is a limit that loses their text.
*/
Route::middleware(['auth:sanctum', 'throttle:authoring'])->group(function (): void {
    Route::get('/cms/articles', [ArticleController::class, 'index']);
    Route::post('/cms/articles', [ArticleController::class, 'store']);
    Route::get('/cms/articles/{article}', [ArticleController::class, 'show']);
    Route::put('/cms/articles/{article}', [ArticleController::class, 'update']);
    Route::post('/cms/articles/{article}/publish', [ArticleController::class, 'publish']);
    Route::delete('/cms/articles/{article}', [ArticleController::class, 'destroy']);
});
