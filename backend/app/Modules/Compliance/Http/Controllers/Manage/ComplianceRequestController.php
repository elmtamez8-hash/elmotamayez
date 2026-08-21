<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compliance\Actions\PlaceLegalHold;
use App\Modules\Compliance\Actions\ReleaseLegalHold;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Http\Requests\ExecuteDataRequestRequest;
use App\Modules\Compliance\Http\Requests\PlaceLegalHoldRequest;
use App\Modules\Compliance\Http\Resources\DataRequestResource;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The data-protection officer's queue (FR-026 · FR-043).
 *
 * ⚠️ EVERY READ HERE DECLARES `withoutWorkspaceScope()`, INCLUDING INSIDE THE
 * EAGER LOADS. `WorkspaceContext::id()` falls back to `users.last_workspace_id`
 * for EVERY user including a platform officer, so a read left in scope shows one
 * workspace's requests as the platform's queue — and passes its own test on a
 * single-workspace fixture. The bypass is per MODEL, not per query: `->with('x')`
 * runs the related model's global scope inside the relation query and returns null
 * for every row outside the reader's fallback workspace. That one shipped in the
 * audit chain and answered "nothing was bought" with a 200.
 *
 * `data_requests` and `legal_holds` themselves carry no tenant column at all, so
 * nothing here needs the bypass today — it is the RELATIONS that do, and they are
 * written out rather than left for the next person to rediscover.
 *
 * ⚠️ AND EXECUTION IS A HUMAN ACT. `store` deliberately does not dispatch an
 * erasure; it waits here, and running it is what writes `executed_by_user_id`. An
 * irreversible destruction that nobody performed is an audit line nobody can
 * answer for.
 */
class ComplianceRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DataRequest::class);

        $requests = DataRequest::query()
            ->with(['subject' => fn ($query) => $query->select(['id', 'uuid', 'first_name', 'last_name'])])
            // Late first: `due_at` is the legal answering deadline, and the whole
            // reason the `(status, due_at)` index exists.
            ->whereIn('status', [
                DataRequestStatus::Pending->value,
                DataRequestStatus::Processing->value,
                DataRequestStatus::OnHold->value,
            ])
            ->orderBy('due_at')
            ->limit(200)
            ->get();

        return DataRequestResource::collection($requests);
    }

    public function execute(ExecuteDataRequestRequest $request, DataRequest $dataRequest): JsonResponse
    {
        $this->authorize('execute', $dataRequest);

        /*
        | ⚠️ A CONDITIONAL UPDATE, so two officers pressing execute together run the
        | request once. It writes `executed_by_user_id` in the SAME statement that
        | claims it — a second write is a second round trip in which the other
        | officer claims it too, and the audit line then names whichever of them
        | wrote last rather than whoever actually ran it.
        |
        | The status stays `pending`, because that is what `FulfilDataRequestJob`
        | claims on. This statement decides WHO and WHETHER; the job decides when.
        */
        $claimed = DataRequest::query()
            ->whereKey($dataRequest->getKey())
            ->where('status', DataRequestStatus::Pending->value)
            ->whereNull('executed_by_user_id')
            ->update([
                'executed_by_user_id' => $this->currentUser($request)->getKey(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw new HttpException(409, 'هذا الطلب لم يعد في انتظار التنفيذ.');
        }

        FulfilDataRequestJob::dispatch((int) $dataRequest->getKey());

        return DataRequestResource::make($dataRequest->refresh())->response();
    }

    public function refuse(ExecuteDataRequestRequest $request, DataRequest $dataRequest): JsonResponse
    {
        $this->authorize('execute', $dataRequest);

        /*
        | ⚠️ A REFUSAL CARRIES A REASON OR IT IS NOT ONE. FR-026 wants the record of
        | who answered and why; a refused request with an empty reason is a legal
        | answer nobody can defend, and the person on the other end is told no with
        | no way to know whether to challenge it.
        */
        $claimed = DataRequest::query()
            ->whereKey($dataRequest->getKey())
            ->whereIn('status', [
                DataRequestStatus::Pending->value,
                DataRequestStatus::OnHold->value,
            ])
            ->update([
                'status' => DataRequestStatus::Refused->value,
                'refusal_reason' => $request->reason(),
                'executed_by_user_id' => $this->currentUser($request)->getKey(),
                'completed_at' => now(),
                // The lock goes with the close: the person may ask again.
                'open_key' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw new HttpException(409, 'هذا الطلب لم يعد قابلاً للرفض.');
        }

        return DataRequestResource::make($dataRequest->refresh())->response();
    }

    public function hold(PlaceLegalHoldRequest $request, PlaceLegalHold $action): JsonResponse
    {
        $this->authorize('manage', LegalHold::class);

        $subject = User::query()->where('uuid', $request->subjectUuid())->first();

        if ($subject === null) {
            throw new HttpException(404, 'لا يوجد حسابٌ بهذا المعرّف.');
        }

        $hold = $action->handle($subject, $this->currentUser($request), $request->reason());

        return response()->json([
            'uuid' => $hold->uuid,
            'reason' => $hold->reason,
            'placed_at' => $hold->placed_at->toIso8601String(),
        ], 201);
    }

    /**
     * ⚠️ A `DELETE` THAT RELEASES RATHER THAN DESTROYS. The row is the record that a
     * hold existed and who lifted it — deleting it would remove the evidence that an
     * erasure was ever suspended, which is the one thing an auditor asks about
     * afterwards.
     */
    public function release(Request $request, LegalHold $legalHold, ReleaseLegalHold $action): JsonResponse
    {
        $this->authorize('manage', LegalHold::class);

        $hold = $action->handle($legalHold, $this->currentUser($request));

        return response()->json([
            'uuid' => $hold->uuid,
            'released_at' => $hold->released_at?->toIso8601String(),
        ]);
    }

    /** Named so a reader can find the permission set without leaving this file. */
    public const PERMISSIONS = [
        'queue' => Permissions::COMPLIANCE_REQUESTS_EXECUTE,
        'holds' => Permissions::COMPLIANCE_HOLDS_MANAGE,
    ];
}
