<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Modules\Payments\Enums\CallbackResult;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One notification from a payment provider.
 *
 * ⚠️ IT USES BelongsToWorkspace EVEN THOUGH ITS TENANT IS NULLABLE, and the two
 * facts belong together. The trait is what makes a resolved row invisible to
 * another workspace; the null is what an unresolved row honestly is. A row still
 * carrying null is reachable only by the deferred worker and by platform-level
 * readers, which is exactly the set that should see a callback nobody can yet
 * attribute.
 *
 * @property ?int $workspace_id
 * @property ?CallbackResult $result
 * @property bool $signature_valid
 */
class ProviderCallback extends BaseModel
{
    use BelongsToWorkspace;
    use HasUuid;

    protected $fillable = [
        'workspace_id',
        'payment_transaction_id',
        'provider',
        'external_id',
        'signature_valid',
        'payload',
        'received_at',
        'processed_at',
        'attempts',
        'result',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
            'result' => CallbackResult::class,
        ];
    }

    /** @return BelongsTo<PaymentTransaction, $this> */
    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
