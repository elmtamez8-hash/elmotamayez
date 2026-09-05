<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Jobs\ProcessProviderCallbackJob;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Payments\Support\CallbackPayloadSanitizer;
use App\Modules\Payments\Support\CallbackRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function __construct(private readonly CallbackRecorder $recorder) {}

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

        // ⚠️ The JOB carries the row's id and the SCRUBBED event, never the raw
        // body. A job that serialises the raw payload drops it into `failed_jobs`
        // — a sink neither sanitizer can reach. It did exactly that until
        // 2026-09-05, under this comment.
        ProcessProviderCallbackJob::dispatch($callback->getKey(), $provider, $event)
            ->onQueue('payments');

        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * ⚠️ THE WRITE ITSELF LIVES IN {@see CallbackRecorder}, and it moved there
     * when the reconciliation sweep needed the same thing: a payment settled
     * without a notification is handled by delivering the callback ourselves, so
     * the sweep and this controller must record it identically or the unique
     * index stops meaning what it says.
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
        return $this->recorder->record($provider, $externalId, $signatureValid, $payload, $result);
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
