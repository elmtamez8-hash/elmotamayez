<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\HandleProviderCallback;
use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Models\ProviderCallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Applies a stored callback, off the request.
 *
 * ⚠️ IT CARRIES THE PARSED EVENT, NEVER THE RAW BODY — and it carried the raw
 * body until 2026-09-05, under a docblock claiming the opposite. `SerializesModels`
 * puts every constructor property into the queue payload and then into
 * `failed_jobs.payload` on the first exception: a sink neither sanitizer reaches,
 * holding exactly the bytes they exist to keep out of storage, for as long as the
 * failed row lives.
 *
 * {@see CallbackEvent} is scrubbed AT THE CONTRACT BOUNDARY — `parseCallback()`
 * is required to drop anything a provider echoes that we must not keep — so what
 * travels now is the reference, the amount, the status and the provider's own id.
 * The controller already parsed it before recording the row, so this also removes
 * a second parse of the same bytes rather than adding work anywhere.
 *
 * ⚠️ IT ENTERS NO WORKSPACE, and that is checked rather than assumed. There is
 * no tenant in context on a webhook — no user, so WorkspaceScope adds no
 * condition — so the Action states `withoutWorkspaceScope()` on every read, and
 * each listener downstream enters the workspace it needs itself, with
 * `forWorkspace()` (CreateEnrollmentFromOrder:58). A `set()` here would be the
 * leak the constitution forbids in a queued worker, and a `forWorkspace()`
 * wrapping nothing would be theatre.
 *
 * ⚠️ `tries` AND `backoff` ARE DECLARED, from config. A job with neither, on a
 * supervisor with neither, retries FOREVER against a reference that will never
 * exist — and the provider was answered 202, so nobody is waiting on the result
 * to notice. At the limit the row becomes `abandoned` and joins the unresolved
 * count (FR-017).
 */
class ProcessProviderCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $callbackId,
        public readonly string $provider,
        public readonly CallbackEvent $event,
    ) {}

    public function tries(): int
    {
        return (int) config('payments.callback.max_attempts', 12);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('payments.callback.backoff_seconds', [60]);

        return $backoff;
    }

    public function handle(HandleProviderCallback $handle): void
    {
        $callback = ProviderCallback::query()
            ->withoutWorkspaceScope()
            ->find($this->callbackId);

        if ($callback === null || $callback->processed_at !== null) {
            return;
        }

        $callback->increment('attempts');

        $result = $handle->handle($callback, $this->event);

        if ($result !== CallbackResult::Deferred) {
            return;
        }

        // Still no transaction to attach to. Retry, unless this was the last
        // attempt — in which case say so on the row rather than dropping it.
        if ($callback->attempts >= $this->tries()) {
            $callback->forceFill([
                'result' => CallbackResult::Abandoned,
                'processed_at' => now(),
            ])->save();

            Log::warning('007 callback abandoned', [
                'callback_uuid' => $callback->uuid,
                'provider' => $this->provider,
                'attempts' => $callback->attempts,
            ]);

            return;
        }

        $this->release($this->backoff()[min($callback->attempts, count($this->backoff())) - 1] ?? 60);
    }
}
