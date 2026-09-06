<?php

declare(strict_types=1);

use App\Modules\Certificates\Http\Controllers\CertificateController;
use App\Modules\Certificates\Http\Controllers\CertificateDesignController;
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

    /*
    | The teacher's design gallery. Guarded by `CertificateDesignPolicy` on
    | `certificates.regenerate` — at the door, never on the screen alone.
    |
    | ⚠️ Each route lands in the phase that builds its caller (SC-009). These two
    | are read by `manage/certificates/design/page.tsx`; the five deleted
    | `/certificate-templates` routes are the reason this feature exists at all —
    | a table, a model, a controller, two requests, a resource and five routes with
    | not one caller under `frontend/src`.
    */
    Route::get('/certificate-designs', [CertificateDesignController::class, 'index']);
    Route::post('/certificate-designs', [CertificateDesignController::class, 'store']);
    Route::patch('/certificate-designs/{design}', [CertificateDesignController::class, 'update']);
    Route::delete('/certificate-designs/{design}', [CertificateDesignController::class, 'destroy']);
});
