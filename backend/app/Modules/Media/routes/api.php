<?php

declare(strict_types=1);

use App\Modules\Media\Http\Controllers\MediaAssetController;
use App\Modules\Media\Http\Controllers\PlaybackController;
use Illuminate\Support\Facades\Route;

/*
| Loaded by App\Shared\Modules\Module, which already applies the /api/v1 prefix
| and the `api` middleware group. Do not repeat either here.
*/

/*
| Streaming is reached without a bearer token because a <video> element cannot
| send an Authorization header. The guard is the grant row itself, re-checked on
| every range request — which is what makes playback stop mid-file when the
| session ends or the watermark stops renewing.
|
| The route is NOT model-bound: implicit binding would resolve the grant before
| the guard runs, and a public route bound to a model is exactly the pattern the
| project forbids.
*/
Route::get('/playback/{grant}/stream', [PlaybackController::class, 'stream'])
    ->name('media.playback.stream');

// The local provider's upload ticket points here. A commercial provider points
// its ticket at its own host and this route simply goes unused.
Route::put('/media/upload/{token}', [MediaAssetController::class, 'receiveUpload'])
    ->name('media.upload.receive');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/lessons/{lesson}/assets', [MediaAssetController::class, 'store']);
    Route::get('/media/assets/{asset}', [MediaAssetController::class, 'show']);
    Route::post('/media/assets/{asset}/complete', [MediaAssetController::class, 'complete']);
    Route::delete('/media/assets/{asset}', [MediaAssetController::class, 'destroy']);

    Route::post('/lessons/{lesson}/playback', [PlaybackController::class, 'issue'])
        ->middleware('throttle:playback');
    Route::post('/playback/{grant}/renew', [PlaybackController::class, 'renew']);
});
