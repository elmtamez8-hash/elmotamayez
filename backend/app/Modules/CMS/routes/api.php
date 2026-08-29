<?php

declare(strict_types=1);

use App\Modules\CMS\Http\Controllers\ArticleController;
use Illuminate\Support\Facades\Route;

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
