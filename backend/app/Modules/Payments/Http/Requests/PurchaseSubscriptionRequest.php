<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Data\SubscriptionIntent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What the one subscription screen sends (spec 027 · FR-006 · FR-012).
 *
 * ⚠️ THE PLAN AND THE GROUP ARE IN THE BODY, NOT IN THE PATH. Route-model
 * binding resolves `{plan}` or `{cohort}` BEFORE any guard runs, and
 * `BelongsToWorkspace` protects neither on a buyer's path — a student is a
 * member of no workspace, so `WorkspaceContext::id()` is null and
 * `WorkspaceScope` adds no condition at all. Both uuids are resolved inside
 * `PurchaseSubscription`, where the coverage can be proved.
 *
 * ⚠️ AND THE SHAPE IS EXCLUSIVE, NOT MERELY REQUIRED. `prohibited_unless` is
 * what stops a private-hours purchase carrying a group uuid that the Action
 * would then ignore in silence — the buyer would have chosen a group and been
 * given something else.
 */
class PurchaseSubscriptionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan_uuid' => ['required', 'uuid'],
            'mode' => ['required', 'string', 'in:'.SubscriptionIntent::MODE_COHORT.','.SubscriptionIntent::MODE_PRIVATE],
            'cohort_uuid' => [
                'required_if:mode,'.SubscriptionIntent::MODE_COHORT,
                'prohibited_unless:mode,'.SubscriptionIntent::MODE_COHORT,
                'uuid',
            ],
        ];
    }

    /**
     * ⚠️ `mode` IS OVERRIDDEN HERE AND NOT IN `lang/ar/validation.php`. That
     * array is keyed by field NAME across the whole application, and
     * `UpdateBillingSettingsRequest` already owns `mode` with a different
     * meaning («نمط الفوترة» — prepaid or deferred). One name cannot carry two
     * labels, so the narrower one lives with the request that needs it.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['mode' => 'نوع الاشتراك'];
    }
}
