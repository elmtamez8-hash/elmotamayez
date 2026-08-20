<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Gamification\Actions\BuildProgressPayload;
use App\Modules\Gamification\Http\Resources\ProgressResource;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function __construct(private readonly BuildProgressPayload $payload) {}

    /** The student's own profile (FR-041). */
    public function me(Request $request): ProgressResource
    {
        [$progress, $context] = $this->payload->handle($this->currentUser($request));

        return new ProgressResource($progress, $context);
    }

    /**
     * A teacher reading one of their own students (FR-042 · NFR-001أ).
     *
     * ⚠️ TWO GATES, AND THE PERMISSION ALONE IS NOT ONE OF THEM. `student_progress`
     * is PLATFORM-owned and carries no workspace_id, so no global scope stands
     * between a teacher and every student on the platform — exactly the exposure
     * ParentStudentRelationPolicy was written for. The permission answers "may
     * this role ever look?"; the active enrollment answers "at this student?".
     *
     * ⚠️ AND THE STUDENT IS RESOLVED, NOT ROUTE-BOUND. `{user}` bound implicitly
     * would load any account on the platform before a single check ran, and the
     * response would carry their name back — an identity probe with a permission
     * check bolted on afterwards.
     */
    public function show(Request $request, string $user): ProgressResource
    {
        $reader = $this->currentUser($request);

        abort_unless($reader->can(Permissions::PROGRESS_VIEW_STUDENT), 403);

        $workspaceId = app(WorkspaceContext::class)->id();

        abort_if($workspaceId === null, 403);

        $student = User::query()->where('uuid', $user)->first();

        /*
         * ⚠️ ONE ANSWER FOR "NO SUCH STUDENT" AND FOR "NOT YOURS". A distinct 404
         * would turn this endpoint into a way of asking whether an account exists,
         * which is the oracle the uuid was chosen to avoid.
         */
        abort_if(
            $student === null
            || ! app(EnrollmentDirectory::class)->hasActiveEnrollmentInWorkspace($student, $workspaceId),
            403,
        );

        [$progress, $context] = $this->payload->handle($student);

        return new ProgressResource($progress, $context);
    }
}
