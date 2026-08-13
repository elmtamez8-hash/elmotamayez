<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Models\ProviderCallback;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writing down that a provider notification arrived, exactly once.
 *
 * Extracted from the webhook controller when the reconciliation sweep needed the
 * same thing: a payment the provider settled without telling us is handled by
 * DELIVERING THE CALLBACK OURSELVES, so the sweep and the webhook must record
 * their notification identically or the unique index stops meaning what it says.
 * Two copies of this method would be two answers to "have we seen this event".
 *
 * ⚠️ insertOrIgnore WITH `uuid` AND `created_at` IN THE ARRAY, then a read back,
 * then a throw.
 *
 * The query builder does not boot the model, so HasUuid never fires — and on
 * MySQL the resulting NOT NULL violation is downgraded to a warning and `''` is
 * stored, after which every later row collides with it on unique(uuid) and is
 * silently skipped for ever. Both columns are therefore passed by hand.
 *
 * `create()` in a try/catch was rejected in round 7 of 006 for the reason that
 * applies here too: it cannot tell a real failure — a null, a foreign key, an
 * out-of-range value — from a duplicate. Zero affected rows means EITHER, so the
 * row is fetched by its key and a miss throws.
 */
class CallbackRecorder
{
    /** @param  array<array-key, mixed>  $payload */
    public function record(
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

    /** Whether this row is a new notification rather than one already recorded. */
    public function isFresh(ProviderCallback $callback): bool
    {
        return $callback->result === null;
    }
}
