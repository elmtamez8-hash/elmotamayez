<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\AdvanceBreachReport;
use App\Modules\Compliance\Enums\BreachStatus;
use App\Modules\Compliance\Http\Requests\AdvanceBreachReportRequest;
use App\Modules\Compliance\Http\Resources\BreachReportResource;
use App\Modules\Compliance\Models\BreachReport;
use App\Modules\Compliance\Policies\BreachReportPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The officer's breach queue (FR-040 · SC-020).
 *
 * ⚠️ IT LIVES UNDER `manage/compliance` RATHER THAN AT THE PATH THE TASK NAMED.
 * Every other officer surface in this phase is there, the permission check is the
 * same, and a queue that sits one prefix away is a screen somebody builds a second
 * navigation entry for.
 *
 * ⚠️ AND THE LIST IS ORDERED BY `created_at`, NOT BY STATUS. The deadline runs from
 * the moment a report arrived, so the oldest untouched one is the most urgent
 * regardless of how far along anything else is — sorting by status buries it under
 * whatever was worked on most recently.
 */
class BreachController extends Controller
{
    public function __construct(private readonly BreachReportPolicy $policy) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorise($request);

        $reports = BreachReport::query()
            ->where('status', '!=', BreachStatus::Closed->value)
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        return BreachReportResource::collection($reports);
    }

    public function update(
        AdvanceBreachReportRequest $request,
        BreachReport $breachReport,
        AdvanceBreachReport $action,
    ): JsonResponse {
        $this->authorise($request);

        /*
        | The refusals — a status walking backwards, a `notified` claimed before
        | either notification was recorded — are `DomainException`s thrown by the
        | Action, which `bootstrap/app.php` renders as a 422 carrying its own
        | sentence. Reshaping them here would be a second place the wording lives.
        */
        $advanced = $action->handle($breachReport, $request->status(), $request->triage());

        return BreachReportResource::make($advanced)->response();
    }

    private function authorise(Request $request): void
    {
        if (! $this->policy->manage($this->currentUser($request))) {
            throw new AccessDeniedHttpException('لا تملك صلاحية لهذا الإجراء.');
        }
    }
}
