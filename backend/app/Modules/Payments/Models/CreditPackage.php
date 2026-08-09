<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Shared\Traits\HasUuid;

/**
 * A package template. Platform-owned reference data (constitution v1.2.0 §I,
 * kind ب): no BelongsToWorkspace, no individual owner, so the write permission
 * BILLING_PACKAGES_MANAGE is its only guard.
 *
 * There is no price column, and that is the point. The price is computed per
 * course, because its input is the approved settlement rate of THAT course's
 * teacher — a price stored here would be one price for every teacher on the
 * platform. See Payments\Support\CostPlusPricing.
 *
 * ClassSessionType is imported from LiveSessions on purpose. Principle III
 * forbids reaching into another module's ACTIONS and MODELS; this is a value
 * enum with two cases, and the alternative — a second `individual`/`group` enum
 * owned by Payments — is two vocabularies for one fact, which drifts the day
 * either module adds a third session type. The operating fee is keyed by these
 * same two strings in config/billing.php.
 *
 * @property int $credits
 * @property ClassSessionType $session_type
 * @property bool $is_active
 */
class CreditPackage extends BaseModel
{
    use HasUuid;

    protected $fillable = [
        'name',
        'credits',
        'session_type',
        'validity_days',
        'is_active',
        'sort_order',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'session_type' => ClassSessionType::class,
            'validity_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
