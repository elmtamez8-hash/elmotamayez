<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Jobs\ProcessProviderCallbackJob;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Payments\Support\CallbackPayloadSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The provider's notification endpoint.
 *
 * ⚠️ NO FormRequest, AND THAT IS A DELIBERATE DEPARTURE FROM THE CONVENTION —
 * declared in plan.md §Complexity Tracking and written here, where it is read.
 * FR-005 requires the signature to be verified BEFORE the payload is touched,
 * and a FormRequest decodes the body before the controller runs. The signature
 * also covers the exact bytes that were sent: json_decode followed by re-encode
 * is not those bytes, so the raw body is what gets verified.
 *
 * ⚠️ 202 EVEN FOR A BAD SIGNATURE. A distinguishable response is an oracle that
 * tells an attacker when they are getting close. SC-002 asks for refusal AND a
 * record — not for a report to the sender.
 *
 * ⚠️ AND A REFUSED CALLBACK'S `external_id` IS NULL. Not read from the body,
 * which would mean parsing before verifying and would let the attacker choose
 * the deduplication key; and not a constant, which would make one junk request
 * turn every later refusal into a "duplicate" and silence the very log SC-002
 * requires. NULL does not collide with NULL — the same property `captured_order_id`
 * relies on.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentProviderRegistry $registry): JsonResponse
    {
        // An unregistered identifier is a route that does not exist. So is a
        // registered provider that cannot receive callbacks — the registry
        // resolves it, and its verifySignature() refuses everything.
        $implementation = $registry->get($provider);

        $rawBody = $request->getContent();

        $valid = $implementation->verifySignature($rawBody, $this->headerMap($request));

        if (! $valid) {
            $this->record($provider, null, false, $this->rawForStorage($rawBody), CallbackResult::RejectedSignature);

            return response()->json(['status' => 'accepted'], 202);
        }

        $event = $implementation->parseCallback($rawBody);

        $callback = $this->record(
            $provider,
            $event->externalId,
            true,
            CallbackPayloadSanitizer::scrub($event->safePayload),
            null,
        );

        // Already recorded under this event id — the fast path, not the
        // guarantee. The effect-level guarantee is one capture per order.
        if ($callback->result === CallbackResult::Accepted) {
            return response()->json(['status' => 'accepted'], 202);
        }

        // ⚠️ The JOB carries the row's id, never the body. A job that serialises
        // the raw payload drops it into `failed_jobs` — a sink neither sanitizer
        // can reach.
        ProcessProviderCallbackJob::dispatch($callback->getKey(), $provider, $rawBody)
            ->onQueue('payments');

        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * ⚠️ insertOrIgnore WITH `uuid` AND `created_at` IN THE ARRAY, then a read
     * back, then a throw.
     *
     * The query builder does not boot the model, so HasUuid never fires — and on
     * MySQL the resulting NOT NULL violation is downgraded to a warning and `''`
     * is stored, after which every later row collides with it on unique(uuid)
     * and is silently skipped for ever. Both columns are therefore passed by
     * hand.
     *
     * `create()` in a try/catch was rejected in round 7 of 006 for the reason
     * that applies here too: it cannot tell a real failure — a null, a foreign
     * key, an out-of-range value — from a duplicate. Zero affected rows means
     * EITHER, so the row is fetched by its key and a miss throws.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function record(
        string $provider,
        ?string $externalId,
        bool $signatureValid,
        array $payload,
        ?CallbackResult $result,
    ): ProviderCallback {
        $now = now();

        $inserted = DB::table('provider_callbacks')->insertOrIgnore([
            'uuid' => (string) Str::orderedUuid(),
            'workspace_id' => null,
            'payment_transaction_id' => null,
            'provider' => $provider,
            'external_id' => $externalId,
            'signature_valid' => $signatureValid,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'received_at' => $now,
            'processed_at' => $result === null ? null : $now,
            'attempts' => 0,
            'result' => $result?->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $existing = ProviderCallback::query()
            ->withoutWorkspaceScope()
            ->where('provider', $provider)
            ->when($externalId === null, fn ($query) => $query->whereNull('external_id')->latest('id'))
            ->when($externalId !== null, fn ($query) => $query->where('external_id', $externalId))
            ->first();

        if ($existing === null) {
            // Zero rows AND nothing to read back: the insert failed for a reason
            // that was not a duplicate, and swallowing it would report success
            // on a callback that was never stored.
            throw new RuntimeException('Failed to record provider callback.');
        }

        if ($inserted === 0 && $existing->result === null) {
            // A duplicate of something still in flight — leave it alone.
            $existing->setAttribute('result', CallbackResult::Duplicate);
        }

        return $existing;
    }

    /**
     * A refused body is the attacker's bytes, so it is scrubbed by the
     * provider-agnostic sanitizer before it is stored — the provider's own
     * parser never saw it.
     *
     * @return array<array-key, mixed>
     */
    private function rawForStorage(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        return CallbackPayloadSanitizer::scrub(
            is_array($decoded) ? $decoded : ['_raw' => $rawBody],
        );
    }

    /** @return array<string, string> */
    private function headerMap(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        return $headers;
    }
}
