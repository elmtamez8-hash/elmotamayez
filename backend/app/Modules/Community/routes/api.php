<?php

declare(strict_types=1);

use App\Modules\Community\Http\Controllers\AssistantController;
use App\Modules\Community\Http\Controllers\ChatAttachmentController;
use App\Modules\Community\Http\Controllers\ConversationController;
use App\Modules\Community\Http\Controllers\Manage\AssistantController as ManageAssistantController;
use App\Modules\Community\Http\Controllers\Manage\PeriodicReviewController as ManagePeriodicReviewController;
use App\Modules\Community\Http\Controllers\MessageController;
use App\Modules\Community\Http\Controllers\ModerationController;
use App\Modules\Community\Http\Controllers\SessionChatController;
use App\Modules\Community\Http\Controllers\StudentReviewController;
use Illuminate\Support\Facades\Route;

/*
| Spec 010 — the Community module's API surface.
|
| Auto-loaded by `App\Shared\Modules\Module::registerRoutes()`, which prefixes
| `/api/v1` and applies the `api` middleware group (and with it
| `EnsureCurrentWorkspace`). Never repeat either here.
|
| ⚠️ NO ROUTE A STUDENT CAN REACH BINDS A MODEL IMPLICITLY. A student is a member
| of no workspace at all, so `WorkspaceContext::id()` is null for them and
| `WorkspaceScope` adds no condition — an implicit `{conversation}` or
| `{message}` binding resolves ANY workspace's row before a single policy runs.
| Every identifier on those routes is a bare `uuid` resolved INSIDE the Action,
| after the membership check. The `RedeemReward` pattern from 009, literally.
|
| The `/manage/*` routes are the exception, and the exemption is the READER rather
| than the route: everybody who reaches one is a workspace member, so the scope
| resolves and another workspace's uuid 404s before the policy runs. Two of them
| take that exemption — `{assignment}` and `{review}` — and each says so where it
| is defined.
*/

Route::middleware('auth:sanctum')->group(function (): void {
    /*
    | The team screen (US1).
    |
    | ⚠️ `{assignment}` IS AN IMPLICIT BINDING, and the exemption
    | is the reader rather than the route: `assistant_assignments` is
    | workspace-scoped and everybody who can reach these three is a MEMBER, so the
    | scope resolves and another workspace's uuid 404s before the policy runs.
    | Nothing a student can reach may copy it.
    |
    | The writes carry `throttle:authoring`, the named limiter the bank and the
    | grading board already use — an inline `throttle:N,M` shares one bucket with
    | every other inline limit on the platform.
    */
    Route::get('/manage/assistants', [ManageAssistantController::class, 'index']);

    Route::middleware('throttle:authoring')->group(function (): void {
        Route::put('/manage/assistants/{assignment}/scope', [ManageAssistantController::class, 'scope']);
        Route::delete('/manage/assistants/{assignment}', [ManageAssistantController::class, 'destroy']);
    });

    // The assistant's own view of where they work. No permission: the filter is
    // their own id, which is ownership and not authorisation.
    Route::get('/assistants/me', [AssistantController::class, 'me']);

    /*
    | The private chat (US2).
    |
    | ⚠️ `{conversation}` AND `{message}` ARE STRINGS, NOT MODELS. Every one of
    | these is reachable by a student, and a student is a member of no workspace —
    | so `WorkspaceScope` adds no condition for them and an implicit binding would
    | resolve ANOTHER teacher's row before a policy ran. Resolved inside the
    | Actions, after the check.
    |
    | Writes carry `throttle:chat-write`, whose ceiling is a `platform_settings`
    | row. Reads are unthrottled: they are scoped to the caller's own threads, and
    | a limit on reading a chat is a limit on scrolling one.
    */
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);

    Route::middleware('throttle:chat-write')->group(function (): void {
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
        Route::delete('/messages/{message}', [MessageController::class, 'destroy']);
        Route::post('/messages/{message}/helpful', [SessionChatController::class, 'helpful']);

        /*
        | Somewhere to put a picture or a voice note (`FR-060`).
        |
        | ⚠️ ON THE WRITE BUCKET, because it IS a write: it creates a `media_assets`
        | row and reserves storage at the provider. Left on the read side, a script
        | could mint upload tickets faster than any ceiling could refuse the files.
        */
        Route::post('/conversations/{conversation}/attachments', [ChatAttachmentController::class, 'store']);
    });

    /*
    | The public rooms (US3).
    |
    | ⚠️ RESOLVING IS A `GET` THAT MAY WRITE THE ROOM, and it is idempotent by a
    | unique index rather than by a second endpoint nobody remembers to call. See
    | `ResolveSessionConversation`.
    */
    Route::get('/class-sessions/{session}/chat', [SessionChatController::class, 'session']);
    Route::get('/lessons/{lesson}/chat', [SessionChatController::class, 'lesson']);

    /*
    | ⚠️ REPORTING HAS ITS OWN BUCKET. Somebody throttled for writing must still be
    | able to report what is being written at them — share one counter with
    | `chat-write` and the loudest participant in a room silences the complaint
    | about themselves.
    */
    Route::post('/messages/{message}/report', [ModerationController::class, 'report'])
        ->middleware('throttle:chat-report');

    // A public review, through the SAME moderation path and the same bucket
    // (FR-034). `{review}` is a bare uuid resolved inside the Action: this one is
    // reachable by a student, so it takes no implicit binding.
    Route::post('/reviews/{review}/report', [ModerationController::class, 'reportReview'])
        ->middleware('throttle:chat-report');

    /*
    | The periodic assessment (US4).
    |
    | ⚠️ THE TWO SIDES ARE TWO ROUTES WITH TWO DIFFERENT BINDING RULES, on purpose.
    | `{review}` on the manage side is an implicit binding, safe because every
    | reader there is a workspace MEMBER — the `{assignment}` exemption above.
    | `/students/me/reviews` is reachable by a student, who is a member of nothing,
    | so it takes no identifier at all and filters on the caller's own id.
    |
    | Writes carry `throttle:authoring`, the named limiter the bank and the grading
    | board already use.
    */
    Route::get('/manage/students/{student}/reviews', [ManagePeriodicReviewController::class, 'index']);

    Route::middleware('throttle:authoring')->group(function (): void {
        Route::post('/manage/periodic-reviews', [ManagePeriodicReviewController::class, 'store']);
        Route::post('/manage/periodic-reviews/{review}/publish', [ManagePeriodicReviewController::class, 'publish']);
    });

    Route::get('/students/me/reviews', [StudentReviewController::class, 'index']);

    // Hiding, banning, lifting — all rows, and there is no DELETE.
    Route::post('/moderation/actions', [ModerationController::class, 'store'])
        ->middleware('throttle:moderation-write');
});

/*
| The bytes themselves (`FR-061`).
|
| ⚠️ OUTSIDE THE `auth:sanctum` GROUP AND GUARDED BY `signed`, BECAUSE AN `<img>`
| CARRIES NO BEARER TOKEN. The same fact put captions behind a grant URL in 019.
| The signature is minted inside `MessageResource`, which only renders for a
| reader `ConversationPolicy::view()` has already admitted, and it lasts fifteen
| minutes — a short-lived capability, named as one rather than dressed up as an
| authorisation check.
|
| ⚠️ AND IT IS NOT A PUBLIC ROUTE IN THE `publiclyListed()` SENSE. Nothing here is
| reachable without a signature, so `PublicExposureTest`'s allowlist does not
| apply — but the route is unauthenticated, so it carries a named limiter like
| every other unauthenticated write-adjacent surface on the platform.
*/
Route::get('/chat-media/{message}', [ChatAttachmentController::class, 'show'])
    ->middleware(['signed', 'throttle:public'])
    ->name('chat.attachment');
