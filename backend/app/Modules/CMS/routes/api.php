<?php

declare(strict_types=1);

use App\Modules\CMS\Http\Controllers\ArticleController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/cms/articles', [ArticleController::class, 'index']);
    Route::post('/cms/articles', [ArticleController::class, 'store']);
    Route::get('/cms/articles/{article}', [ArticleController::class, 'show']);
    Route::put('/cms/articles/{article}', [ArticleController::class, 'update']);
    Route::post('/cms/articles/{article}/publish', [ArticleController::class, 'publish']);
    Route::delete('/cms/articles/{article}', [ArticleController::class, 'destroy']);
});
