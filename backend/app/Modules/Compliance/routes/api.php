<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Controllers\PrivacyCategoryController;
use App\Modules\Compliance\Http\Controllers\PrivacyConsentController;
use Illuminate\Support\Facades\Route;

/*
 * Routes for the Compliance module.
 *
 * `App\Shared\Modules\Module` adds the `/api/v1` prefix and the `api` middleware
 * group automatically — do not repeat either here.
 *
 * Every limiter is NAMED. An inline `throttle:20,1` is banned: `ThrottleRequests`
 * keys guests on `domain|ip` with no route in the hash, so every inline limit
 * shares one counter and the strictest wins for the whole site.
 */

/*
| The catalogue and the policy text — PUBLIC, and that is a decision.
|
| ⚠️ A PRIVACY POLICY BEHIND A LOGIN IS NOT PUBLISHED. Someone deciding whether to
| create an account for their child has to be able to read what will be collected
| BEFORE creating it, and the categories are the policy in structured form. There
| is nothing here about any person — it is what the platform collects, not what it
| holds about anyone.
*/
Route::middleware('throttle:public')->group(function (): void {
    Route::get('/privacy/categories', [PrivacyCategoryController::class, 'index']);
    Route::get('/privacy/policy', [PrivacyCategoryController::class, 'policy']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    /*
     | Changing which optional categories are consented to (FR-007).
     |
     | ⚠️ THERE IS NO `POST /privacy/consents` HERE, ON PURPOSE.
     | `Payments\Http\Controllers\TermsConsentController::index/store` has served
     | every `ConsentDocument` since 006 — `data_processing` included — with the
     | ip, the user agent, the version from the registry and the guardian check
     | already in place. A second endpoint doing the same thing would be a second
     | writer to a legal record.
     |
     | `throttle:data-rights` keys on the ACCOUNT, never the address: a family
     | behind one router shares an address, and a guardian may hold several
     | children.
     */
    Route::put('/privacy/consents/categories', [PrivacyConsentController::class, 'update'])
        ->middleware('throttle:data-rights');
});
