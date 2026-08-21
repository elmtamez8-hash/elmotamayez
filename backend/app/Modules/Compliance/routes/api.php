<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Controllers\DataRequestController;
use App\Modules\Compliance\Http\Controllers\Manage\ComplianceRequestController;
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

    /*
    | Data-rights requests (FR-015 · FR-019).
    |
    | ⚠️ `throttle:data-rights` ON `/download` AS WELL AS ON `POST`. The expensive
    | half is not only the creation: a download is a signed link minted per press,
    | and an unthrottled one is a mint. The limiter keys on the ACCOUNT, never the
    | address — a family behind one router shares an address, and a guardian may
    | hold several children.
    |
    | ⚠️ AND `{dataRequest}` BINDS BY UUID with a policy behind it, never a scope:
    | `data_requests` is platform-owned and carries no `workspace_id`, and a student
    | is a member of no workspace — so no global scope touches this table on any
    | route a student can reach. `DataRequestPolicy` is the entire guard.
    */
    Route::middleware('throttle:data-rights')->group(function (): void {
        Route::post('/privacy/requests', [DataRequestController::class, 'store']);
        Route::get('/privacy/requests/{dataRequest}/download', [DataRequestController::class, 'download']);
    });

    Route::get('/privacy/requests', [DataRequestController::class, 'index']);

    /*
    | The data-protection officer's queue (FR-026 · FR-043).
    |
    | ⚠️ NO `can:` MIDDLEWARE — the authorisation is `$this->authorize()` INSIDE each
    | method, which is the shape every other controller in this repository uses.
    | `can:` appears nowhere else here, and a route-level gate cannot see the row it
    | is deciding about.
    |
    | ⚠️ AND EXECUTION IS SEPARATE FROM CREATION ON PURPOSE. An erasure opened by a
    | family waits here until a person runs it: that is FR-019's announced execution
    | period, and it is what writes `executed_by_user_id`.
    */
    Route::prefix('manage/compliance')->group(function (): void {
        Route::get('/requests', [ComplianceRequestController::class, 'index']);
        Route::post('/requests/{dataRequest}/execute', [ComplianceRequestController::class, 'execute']);
        Route::post('/requests/{dataRequest}/refuse', [ComplianceRequestController::class, 'refuse']);

        Route::post('/holds', [ComplianceRequestController::class, 'hold']);
        // A release, not a destruction — the row is the record that an erasure was
        // suspended. See `LegalHoldPolicy::delete()`.
        Route::delete('/holds/{legalHold}', [ComplianceRequestController::class, 'release']);
    });
});

/*
| The archive itself.
|
| ⚠️ SIGNED, AND WITHOUT `auth:sanctum` — the same shape as `/playback/{grant}`,
| and for the same reason: a browser following a redirect to another origin does
| not forward an `Authorization` header, so a token-guarded stream behind a `302`
| simply does not work. The five-minute signature IS the credential FR-018
| describes, and the controller re-reads `export_expires_at` before writing a byte,
| so a signature that outlives the archive opens nothing.
*/
/*
| ⚠️ `throttle:public`, NOT `throttle:data-rights`, AND THE DIFFERENCE IS A GLOBAL
| BUCKET. The data-rights limiter keys on `'user:'.$request->user()?->getKey()` —
| and this route deliberately carries no `auth:sanctum`, so `user()` is null and
| every anonymous hit shares the key `'user:'`. Six a minute for the WHOLE
| PLATFORM: the second person to download their archive in the same minute is
| refused their own file. That is the inline-throttle shared-counter defect wearing
| a named limiter, which is precisely what naming them was meant to prevent.
| `throttle:public` is guest-keyed by address, which is the only key there is here.
*/
Route::middleware(['signed', 'throttle:public'])
    ->get('/privacy/exports/{dataRequest}', [DataRequestController::class, 'stream'])
    ->name('compliance.exports.stream');
