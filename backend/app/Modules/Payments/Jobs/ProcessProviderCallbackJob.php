<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\HandleProviderCallback;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Applies a stored callback, off the request.
 *
 * ⚠️ IT CARRIES THE ROW'S ID, NOT THE PARSED EVENT. A job that serialises the
 * body drops it into `failed_jobs` on the first exception — a sink neither
 * sanitizer reaches, holding exactly the bytes they exist to keep out of storage.
 * The raw body travels because the signature was already verified and the
 * provider's parser is the only thing that can read it; it is never persisted by
 * this class.
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
        public readonly string $rawBody,
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

    public function handle(PaymentProviderRegistry $registry, HandleProviderCallback $handle): void
    {
        $callback = ProviderCallback::query()
            ->withoutWorkspaceScope()
            ->find($this->callbackId);

        if ($callback === null || $callback->processed_at !== null) {
            return;
        }

        $callback->increment('attempts');

        $event = $registry->get($this->provider)->parseCallback($this->rawBody);

        $result = $handle->handle($callback, $event);

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
