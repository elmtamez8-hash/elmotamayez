<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\ListStaffAttempts;
use App\Modules\Assessments\Http\Resources\StaffAttemptResource;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Support\GradingSettings;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The staff list of handed-in exam papers — the dashboard's «آخر المحاولات».
 */
class StaffAttemptController extends Controller
{
    public function index(Request $request, ListStaffAttempts $action, GradingSettings $settings): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Attempt::class);

        /*
        | ⚠️ NO WORKSPACE, NO LIST. A null context drops `WorkspaceScope`, and a
        | super admin passes the policy on `before()` — the list would be every
        | teacher's papers on the platform under a title that says «yours».
        */
        $workspace = app(WorkspaceContext::class)->current();
        abort_if($workspace === null, 403);

        $anonymous = $settings->isAnonymous($workspace);

        $page = $action->handle(
            $this->currentUser($request),
            (int) $workspace->getKey(),
            min(max($request->integer('per_page', 15), 1), 50),
            withStudents: ! $anonymous,
        );

        // The collection itself, never `response()->json(...)` around it — that
        // path skips `toResponse()` and drops `links` and `meta` in silence.
        return StaffAttemptResource::collection($page)
            ->additional(['meta' => ['anonymous' => $anonymous]]);
    }
}
