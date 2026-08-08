<?php

declare(strict_types=1);

use App\Modules\Media\Http\Controllers\CaptionController;
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

// A <track> element sends no Authorization header either, so the caption file
// is reached through the same grant and expires with it.
Route::get('/playback/{grant}/captions/{caption}', [CaptionController::class, 'show'])
    ->name('media.captions.show');

// The local provider's upload ticket points here. A commercial provider points
// its ticket at its own host and this route simply goes unused.
Route::put('/media/upload/{token}', [MediaAssetController::class, 'receiveUpload'])
    ->name('media.upload.receive');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/lessons/{lesson}/assets', [MediaAssetController::class, 'store'])
        ->middleware('throttle:upload');
    Route::get('/media/assets/{asset}', [MediaAssetController::class, 'show']);
    Route::post('/media/assets/{asset}/complete', [MediaAssetController::class, 'complete']);
    // View-only / allow-download. A separate route because the teacher flips it
    // long after the file landed.
    Route::put('/media/assets/{asset}/disposition', [MediaAssetController::class, 'disposition'])
        ->middleware('throttle:authoring');
    Route::post('/media/assets/{asset}/captions', [CaptionController::class, 'store']);
    Route::delete('/media/captions/{caption}', [CaptionController::class, 'destroy']);

    // Deleting an asset destroys a teacher's uploaded work irreversibly.
    Route::delete('/media/assets/{asset}', [MediaAssetController::class, 'destroy'])
        ->middleware('2fa.required');

    Route::post('/lessons/{lesson}/playback', [PlaybackController::class, 'issue'])
        ->middleware('throttle:playback');

    // One named file on the item — its attachment, or its own file by uuid.
    // FR-034 puts EVERY uploaded asset behind a short-lived grant, and an
    // attachment a student cannot open is an attachment that was never added.
    Route::post('/lessons/{lesson}/assets/{asset}/playback', [PlaybackController::class, 'issueForAsset'])
        ->middleware('throttle:playback');
    Route::post('/playback/{grant}/renew', [PlaybackController::class, 'renew']);
});
