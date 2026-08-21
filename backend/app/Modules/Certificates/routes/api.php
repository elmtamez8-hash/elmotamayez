<?php

declare(strict_types=1);

use App\Modules\Certificates\Http\Controllers\CertificateController;
use App\Modules\Certificates\Http\Controllers\CertificateTemplateController;
use Illuminate\Support\Facades\Route;

/*
| ⚠️ PUBLIC AND UNAUTHENTICATED, AND IT HAD NO RATE LIMITER AT ALL. The endpoint
| confirms a NAME to whoever holds a code, so an unthrottled one is a name oracle
| that can be walked at machine speed. `throttle:public` is the named guest limiter
| this codebase already defines — an inline `throttle:30,1` is banned, because
| `ThrottleRequests` keys guests on `domain|ip` with no route in the hash, so every
| inline limit shares one counter and the strictest wins for the whole site.
*/
Route::get('/certificates/verify/{code}', [CertificateController::class, 'verify'])
    ->middleware('throttle:public')
    ->name('certificates.verify');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/certificates', [CertificateController::class, 'index']);
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show']);
    Route::post('/certificates/{certificate}/regenerate', [CertificateController::class, 'regenerate']);

    Route::get('/certificate-templates', [CertificateTemplateController::class, 'index']);
    Route::post('/certificate-templates', [CertificateTemplateController::class, 'store']);
    Route::get('/certificate-templates/{template}', [CertificateTemplateController::class, 'show']);
    Route::put('/certificate-templates/{template}', [CertificateTemplateController::class, 'update']);
    Route::delete('/certificate-templates/{template}', [CertificateTemplateController::class, 'destroy']);
});
