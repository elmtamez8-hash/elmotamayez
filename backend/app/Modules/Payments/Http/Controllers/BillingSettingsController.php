<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\UpdateBillingSettings;
use App\Modules\Payments\Data\BillingSettingsData;
use App\Modules\Payments\Enums\BillingCadence;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Http\Requests\UpdateBillingSettingsRequest;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workspace's own billing policy.
 *
 * No `workspace` parameter: the workspace comes from the context the request was
 * already authenticated into. A parameter would make "may I write this
 * workspace?" a question this controller has to answer correctly on every
 * deploy.
 */
class BillingSettingsController extends Controller
{
    public function show(Request $request, BillingSettings $settings, WorkspaceContext $context): JsonResponse
    {
        $workspace = $this->currentWorkspace($context);

        $this->authorize('manageBillingSettings', $workspace);

        return response()->json($this->payload($settings, $workspace));
    }

    public function update(
        UpdateBillingSettingsRequest $request,
        UpdateBillingSettings $action,
        BillingSettings $settings,
        WorkspaceContext $context,
    ): JsonResponse {
        $workspace = $this->currentWorkspace($context);

        $this->authorize('manageBillingSettings', $workspace);

        $action->handle($workspace, BillingSettingsData::fromArray($request->validated()));

        return response()->json($this->payload($settings, $workspace->refresh()));
    }

    /**
     * The workspace this request is operating in.
     *
     * Null is a real state, not an impossible one: a Super Admin operating
     * globally has no current workspace, and WorkspaceScope goes inert for them.
     * There is no platform-wide billing mode to show or set — the mode is a
     * property of one academy — so the honest answer is 403, not a page of
     * defaults that saving would write to nobody.
     */
    private function currentWorkspace(WorkspaceContext $context): Workspace
    {
        $workspace = $context->current();

        if ($workspace === null) {
            throw new AuthorizationException('تعذّر تحديد مكان عملك لتعديل إعدادات الفوترة.');
        }

        return $workspace;
    }

    /**
     * The current policy, plus what else could be chosen and why not.
     *
     * The unavailable options are listed with their REASON rather than omitted.
     * A mode that silently is not in the list reads as a product that does not
     * support it; one greyed out with "not configured yet" reads as a thing that
     * is coming — which is the true statement.
     *
     * @return array<string, mixed>
     */
    private function payload(BillingSettings $settings, Workspace $workspace): array
    {
        $mode = $settings->mode($workspace);
        $cadence = $settings->cadence($workspace);

        return [
            'mode' => $mode->value,
            'mode_label' => $mode->label(),
            'cadence' => $cadence->value,
            'cadence_label' => $cadence->label(),
            'allows_deferral' => $mode->allowsDeferral(),
            'zero_balance_behavior' => $settings->zeroBalanceBehavior($workspace)->value,
            'alert_thresholds' => $settings->alertThresholds($workspace),
            'cadence_allows_credits' => $settings->cadenceAllowsCredits($workspace),
            'modes' => array_map(fn (BillingMode $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
                'unavailable_reason' => $settings->refusalToAdopt($option),
            ], BillingMode::cases()),
            /*
            | Every cadence is available under every mode since Q-11, so the
            | reason is a literal null. The key stays rather than disappearing:
            | it is the one shape the client reads for both lists, and a screen
            | that has to branch on which options carry reasons is a screen that
            | will forget to when the next unready cadence arrives.
            */
            'cadences' => array_map(fn (BillingCadence $option): array => [
                'value' => $option->value,
                'label' => $option->label(),
                'sessions_per_cycle' => $option->sessionsPerCycle(),
                'unavailable_reason' => null,
            ], BillingCadence::cases()),
        ];
    }
}
