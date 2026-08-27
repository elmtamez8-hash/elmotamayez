<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\BuildPracticeFromMistakes;
use App\Modules\Assessments\Http\Resources\MistakeResource;
use App\Modules\Assessments\Http\Resources\PracticeAttemptResource;
use App\Modules\Assessments\Support\MistakeFilterOptions;
use App\Modules\Assessments\Support\MistakeNotebook;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own mistakes, and a paper built from them.
 *
 * ⚠️ ROW OWNERSHIP, NOT A PERMISSION. The student id comes from the token and is
 * never read from the request: a `?student=` parameter here would be FR-020
 * undone by one forgotten check, and there is no reading of somebody else's
 * notebook this endpoint is meant to serve.
 *
 * ⚠️ AND IT USED TO ANSWER EVERY REAL STUDENT `422`.
 *
 * FR-016أ asks for a notebook per teacher, and this endpoint enforced it by
 * refusing to answer without a workspace context — «اختر مساحة عمل لعرض دفتر
 * أخطائك». A student HAS no workspace context: they are a member of no
 * workspace, nothing on their path writes `users.last_workspace_id`, and
 * `WorkspaceContext::id()` is therefore null for every one of them. So the
 * notebook did not exist for anybody it was written for — measured on a real
 * fixture, and invisible to every test here because they all build their student
 * with `addWorkspaceMember()`, which stamps that column and hands the test a
 * person production never creates.
 *
 * The scope is resolved context-FIRST now, the shape {@see StudentScope} uses:
 * the context workspace when there is one (a teacher previewing, an invited
 * member — unchanged behaviour), and otherwise every workspace the reader holds
 * an active enrolment in. What FR-016أ protects survives because **every row
 * names its teacher** and the bar can narrow to one: the student is never shown
 * half their mistakes called all of them, and no question is read inside another
 * teacher's context.
 *
 * ⚠️ AND THE FILTER BAR'S OPTIONS COME FROM {@see MistakeFilterOptions}, which
 * derives them from the notebook's own query. A picker assembled beside the
 * reader offers what the reader refuses — spec 009 shipped exactly that and
 * `MistakeFilterOptionsTest` is what stops it happening twice.
 */
class MistakeController extends Controller
{
    public function index(Request $request, MistakeNotebook $notebook, EnrollmentDirectory $enrollments): JsonResponse
    {
        $mistakes = $notebook->paginate(
            $this->readableWorkspaces($request, $enrollments),
            (int) $this->currentUser($request)->getKey(),
            $this->filters($request),
            (int) $request->integer('per_page', 20),
        );

        return response()->json([
            // Wrapped by hand: JsonResource::withoutWrapping() is on globally.
            'data' => MistakeResource::collection($mistakes->items()),
            'meta' => [
                'total' => $mistakes->total(),
                'current_page' => $mistakes->currentPage(),
                'last_page' => $mistakes->lastPage(),
            ],
        ]);
    }

    /** The filter bar, derived from the notebook itself. */
    public function options(Request $request, MistakeFilterOptions $options, EnrollmentDirectory $enrollments): JsonResponse
    {
        return response()->json($options->for(
            $this->readableWorkspaces($request, $enrollments),
            (int) $this->currentUser($request)->getKey(),
            // ⚠️ THE BAR FOLLOWS THE VIEW. Under «الكل» the list shows fixed
            // questions too, and a bar derived from the standing set alone would
            // be absent from exactly the screen that has the most rows to filter.
            $request->boolean('include_resolved'),
        ));
    }

    public function practice(Request $request, BuildPracticeFromMistakes $action, EnrollmentDirectory $enrollments): JsonResponse
    {
        $workspaceId = $this->practiceWorkspace($request, $enrollments);

        /*
        | ⚠️ A PAPER BELONGS TO ONE TEACHER, WHICH THE NOTEBOOK NO LONGER DOES.
        | The attempt carries a `workspace_id`, its questions are drawn from one
        | bank, and the withholding it is checked against is that teacher's. So
        | when the reader studies with several and has named none, the refusal
        | says which control to use rather than picking a teacher for them —
        | a paper silently built from the alphabetically-first teacher is a
        | revision session about the wrong subject.
        */
        if ($workspaceId === null) {
            return response()->json(['message' => 'اختر المدرّس أولاً لبناء اختبار من أخطائك.'], 422);
        }

        try {
            $attempt = $action->handle(
                $workspaceId,
                $this->currentUser($request),
                $this->filters($request),
                (int) $request->integer('count', 10),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // The same shape the self-generated paper answers with — one screen
        // sits both, and two shapes for one page is two renderers to keep in
        // step.
        return response()->json(['data' => PracticeAttemptResource::make($attempt)], 201);
    }

    /**
     * Which teachers' answers this reader may see.
     *
     * ⚠️ CONTEXT FIRST, ENROLMENTS SECOND — the {@see StudentScope} shape, and
     * for its reason: fix the hole and only the hole. A reader who HAS a
     * workspace context (a teacher previewing their own bank, an invited member)
     * keeps exactly the behaviour they had; a real student, who never has one,
     * gets the teachers they actually study with instead of a `422`.
     *
     * An empty list is «nothing», never «everything»: `whereIn(…, [])` matches
     * no row, which is the direction a scope has to fail in.
     *
     * @return list<int>
     */
    private function readableWorkspaces(Request $request, EnrollmentDirectory $enrollments): array
    {
        $context = app(WorkspaceContext::class)->id();

        return $context !== null
            ? [$context]
            : $enrollments->activeWorkspaceIdsFor($this->currentUser($request));
    }

    /**
     * The single teacher a revision paper is built for, or null to refuse.
     *
     * The ladder is deliberate: a named teacher wins; one readable teacher is
     * chosen silently, because asking a question with one possible answer is a
     * tap that teaches nothing; several with none named is a refusal that says
     * so.
     */
    private function practiceWorkspace(Request $request, EnrollmentDirectory $enrollments): ?int
    {
        $readable = $this->readableWorkspaces($request, $enrollments);
        $teacher = $request->string('teacher')->toString();

        if ($teacher !== '') {
            $named = (int) Workspace::query()->where('uuid', $teacher)->value('id');

            return in_array($named, $readable, true) ? $named : null;
        }

        return count($readable) === 1 ? $readable[0] : null;
    }

    /**
     * ⚠️ THE STUDENT ID IS NEVER A PARAMETER. It comes from the token, here and
     * in every method above — a `?student=` on this endpoint would be FR-020
     * undone by one forgotten check.
     *
     * @return array{teacher?: string, course?: string, exam?: string, concept?: string, lesson?: string, from?: string, to?: string, include_resolved?: bool}
     */
    private function filters(Request $request): array
    {
        return [
            'teacher' => $request->string('teacher')->toString(),
            'course' => $request->string('course')->toString(),
            'exam' => $request->string('exam')->toString(),
            'concept' => $request->string('concept')->toString(),
            'lesson' => $request->string('lesson')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'include_resolved' => $request->boolean('include_resolved'),
        ];
    }
}
