<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\BillingSettingsData;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Switch a workspace's billing policy, from settings, with no deploy (FR-011).
 *
 * ⚠️ IT CHANGES WHAT HAPPENS NEXT AND NOTHING ELSE (FR-012). No entry is
 * rewritten, no balance recomputed, no standing commitment voided. That is not
 * restraint — it is the only version that is correct: the ledger records what
 * was true when each movement happened, and a switch that reached backwards
 * would restate a student's history to match a decision taken after it.
 *
 * The consequence is visible and intended: a student who is 3 credits down when
 * a workspace moves to prepaid STAYS 3 down. The floor rises to zero so they
 * cannot go further, and the existing debt is collected the way it always was.
 * Clearing it on switch would forgive money owed; reversing it would invent a
 * charge.
 */
class UpdateBillingSettings extends Action
{
    public function __construct(private readonly BillingSettings $settings) {}

    public function handle(Workspace $workspace, BillingSettingsData $data): Workspace
    {
        $mode = $data->mode ?? $this->settings->mode($workspace);

        // Asked here, not only in the FormRequest: the Filament panel and any
        // future console command reach this Action, and a rule that lives on the
        // HTTP path alone is a rule with a door beside it.
        $refusal = $this->settings->refusalToAdopt($mode);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        /*
        | ⚠️ THE CADENCE IS NOT NORMALISED, and it used to be.
        |
        | While FR-010ب refused a non-session cadence under a prepaid mode, a
        | mode-only PATCH forced the cadence back to per_session so that leaving
        | deferral did not fail over a field nobody touched. Q-11 lifted the
        | refusal — prepaid monthly is a shape the product sells — and the same
        | line then does the opposite of its purpose: it DESTROYS a legal state,
        | silently resetting a workspace's monthly cadence every time any other
        | field is saved.
        |
        | So a PATCH that omits the cadence leaves it exactly as it was, like
        | every other key here.
        */
        $values = array_filter([
            'mode' => $data->mode?->value,
            'cadence' => $data->cadence?->value,
            'zero_balance_behavior' => $data->zeroBalanceBehavior?->value,
            'alert_thresholds' => $data->alertThresholds,
        ], fn (mixed $value): bool => $value !== null);

        $this->settings->save($workspace, $values);

        return $workspace->refresh();
    }
}
