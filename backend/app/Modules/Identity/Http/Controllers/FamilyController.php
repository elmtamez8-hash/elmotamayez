<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\AcceptRelation;
use App\Modules\Identity\Actions\LinkGuardian;
use App\Modules\Identity\Actions\RevokeRelation;
use App\Modules\Identity\Actions\UpdateRelationPermissions;
use App\Modules\Identity\Data\LinkGuardianData;
use App\Modules\Identity\Http\Requests\LinkGuardianRequest;
use App\Modules\Identity\Http\Requests\UpdateRelationPermissionsRequest;
use App\Modules\Identity\Http\Resources\ParentStudentRelationResource;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Shared\Support\GuardianPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Guardians and the students they follow. Replaces ParentController's child
 * endpoints, which modelled the same thing with no type, permissions or status.
 */
class FamilyController extends Controller
{
    /**
     * The caller's relations, from either side: rows where they are the guardian,
     * plus rows where they are the student.
     *
     * Filtered by user id, not by a scope — this table has none (Constitution I).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->currentUser($request);

        $relations = ParentStudentRelation::query()
            ->where(function ($query) use ($user): void {
                $query->where('guardian_user_id', $user->getKey())
                    ->orWhere('student_user_id', $user->getKey());
            })
            // `student` as well as `guardian`: the resource sends the child's uuid
            // to their own guardian, and a per-row lazy load here is an N+1 by
            // construction — a Resource runs once per row.
            ->with(['guardian', 'student:id,uuid'])
            ->orderBy('id')
            ->get();

        return ParentStudentRelationResource::collection($relations);
    }

    public function store(LinkGuardianRequest $request, LinkGuardian $action): JsonResponse
    {
        $relation = $action->handle(
            $this->currentUser($request),
            LinkGuardianData::fromArray($request->validated()),
        );

        return ParentStudentRelationResource::make($this->hydrate($relation))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $uuid): ParentStudentRelationResource
    {
        $relation = ParentStudentRelation::query()->where('uuid', $uuid)->firstOrFail();

        // 403, not 404: the row exists and the policy is what stops this caller.
        // Hiding that would be indistinguishable from a typo in the uuid.
        abort_unless($this->currentUser($request)->can('view', $relation), 403);

        return ParentStudentRelationResource::make($relation->load(['guardian', 'student:id,uuid']));
    }

    public function update(
        UpdateRelationPermissionsRequest $request,
        string $uuid,
        UpdateRelationPermissions $action,
    ): ParentStudentRelationResource {
        $relation = ParentStudentRelation::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->currentUser($request)->can('update', $relation), 403);

        /** @var list<string> $values */
        $values = $request->validated('permissions');

        $updated = $action->handle($this->currentUser($request), $relation, array_map(
            static fn (string $value): GuardianPermission => GuardianPermission::from($value),
            $values,
        ));

        return ParentStudentRelationResource::make($this->hydrate($updated));
    }

    /**
     * The party who did not ask settles the link (spec 030 · FR-001).
     *
     * The refusal is 403 from the policy and 422 from the Action — the two differ
     * because `DomainException` is mapped globally to 422, and the super admin
     * that `Gate::before` waves past the policy lands on the second. Both carry
     * the same undifferentiated sentence, so the pair is not an oracle.
     */
    public function accept(Request $request, string $uuid, AcceptRelation $action): ParentStudentRelationResource
    {
        $relation = ParentStudentRelation::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->currentUser($request)->can('accept', $relation), 403, 'لا يمكنك البتّ في هذا الطلب.');

        return ParentStudentRelationResource::make(
            $this->hydrate($action->handle($this->currentUser($request), $relation)),
        );
    }

    /**
     * ⚠️ `guardian` IS `whenLoaded`, SO A MISSING EAGER LOAD IS A MISSING NAME —
     * silently, with a 200. Only `index` and `show` loaded it; `store`, `update`
     * and `destroy` built the Resource from a bare model, so three responses have
     * been dropping the guardian's name since the endpoint shipped. It never
     * showed because the screen re-lists after every mutation — and the accept
     * response is the first one a screen has reason to render in place, which is
     * exactly where FR-007's «read who, and what they will see» would vanish.
     */
    private function hydrate(ParentStudentRelation $relation): ParentStudentRelation
    {
        return $relation->load(['guardian', 'student:id,uuid']);
    }

    public function destroy(Request $request, string $uuid, RevokeRelation $action): ParentStudentRelationResource
    {
        $relation = ParentStudentRelation::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->currentUser($request)->can('delete', $relation), 403);

        return ParentStudentRelationResource::make($this->hydrate($action->handle($relation)));
    }
}
