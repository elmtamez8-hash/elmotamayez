<?php

declare(strict_types=1);

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
| ⛔ AND THE SIX AUTHORING ROUTES ARE GONE — deleted 2026-09-05.
|
| `/cms/articles` (index · show · store · update · publish · destroy) was a
| complete second door onto `CmsArticleResource`, and no file under
| `frontend/src` ever called one of them. Two doors onto one act is the shape
| this repository keeps paying for: the publish gate had to be written TWICE the
| day it was found missing, and the index leaked a foreign workspace's article
| that the panel could not have shown.
|
| ⚠️ NOTHING BEHAVIOURAL WENT WITH THEM, WHICH IS WHY THEY COULD GO. The slug is
| generated on the MODEL and the IndexNow announcement is dispatched from
| `Article::booted()`, so the panel has always carried both — the announcement
| moved there precisely because it started in `ArticleController::publish()` and
| a teacher publishing from `/admin` notified nobody. What the Request DID carry
| alone was the unique-slug refusal, and that rule now sits on the panel's own
| slug field.
|
| The two public routes above stay: they are the blog, and they are called.
*/
