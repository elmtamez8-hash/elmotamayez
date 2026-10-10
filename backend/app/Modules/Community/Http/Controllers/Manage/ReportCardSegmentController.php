<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Community\Http\Resources\ReportCardSegmentResource;
use App\Modules\Community\Models\ReportCardSegment;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What the TEACHER sees of the cumulative card — their own segment, and nothing
 * else (FR-041, NFR-001أ).
 *
 * ⚠️ NOT A CARD ENDPOINT WITH A FILTER ON IT. The card is the union of several
 * teachers' judgements of one student, so returning it and hiding the other rows
 * in a Resource is one forgotten `whenLoaded` away from handing a teacher a
 * colleague's grades. The segment IS the teacher's object: a different query
 * against a different table that cannot carry anybody else's row.
 */
class ReportCardSegmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /*
        | ⛔ A TEACHER'S SCREEN, AND IT CHECKED NOTHING (security scan 2026-10-10,
        | F9). Every member of the workspace — a student invited into it included —
        | read every student's grades and attendance here. The permission is the
        | one the screen that calls it (`/manage/students/{uuid}/reviews`) is
        | gated on; a confined assistant lists their own students only.
        */
        abort_unless($this->currentUser($request)->can(Permissions::REVIEWS_PERIODIC_MANAGE), 403);

        $workspaceId = app(WorkspaceContext::class)->id();

        abort_if($workspaceId === null, 403);

        $students = app(AssistantScopeDirectory::class)->scopedStudentIdsFor($this->currentUser($request), $workspaceId);

        $segments = ReportCardSegment::query()
            ->where('workspace_id', $workspaceId)
            ->when($students !== null, fn ($query) => $query->whereIn('student_user_id', $students ?? []))
            ->when(
                $request->query('student') !== null,
                fn ($query) => $query->whereHas(
                    'reportCard',
                    fn ($card) => $card->whereRelation('student', 'uuid', (string) $request->query('student'))
                )
            )
            // ⚠️ `first_name`/`last_name`, NEVER `name` — `users` has no such
            // column, so naming it loads the relation and yields an empty string
            // on every row. Found live in Phase 6, guarded by an assertion on the
            // VALUE rather than on the key.
            ->with([
                'reportCard:id,uuid,student_user_id,period_start,period_end',
                'teacher:id,first_name,last_name',
                'student:id,uuid,first_name,last_name',
            ])
            ->latest('id')
            ->get();

        return ReportCardSegmentResource::collection($segments);
    }
}
