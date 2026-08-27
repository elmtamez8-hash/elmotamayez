<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\PublishExam;
use App\Modules\Assessments\Http\Requests\StoreExamRequest;
use App\Modules\Assessments\Http\Requests\UpdateExamRequest;
use App\Modules\Assessments\Http\Resources\ExamResource;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Support\StudentScope;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function index(Request $request, EnrollmentDirectory $enrollments): JsonResponse
    {
        $this->authorize('viewAny', Exam::class);

        $user = $this->currentUser($request);
        $manages = $user->can(Permissions::EXAMS_VIEW);

        $exams = Exam::query()
            /*
             | ⚠️ THE STUDENT'S OWN SLICE, AND WITHOUT IT THERE IS NO CONDITION AT
             | ALL. `WorkspaceScope` adds nothing when the context is null, which
             | it always is for a student — so this list answered any signed-in
             | student with every published paper on the PLATFORM. See
             | {@see StudentScope} for the measurement and the second predicate.
             |
             | Applied FIRST so its own group closes before the status
             | disjunction below opens: two `orWhere`s at one level is how the
             | filter beneath them stopped biting.
             */
            ->when(! $manages, fn ($q) => StudentScope::applyIfUnscoped($q, $user, $enrollments))
            /*
             | ⚠️ THE STATUS DISJUNCTION IS GROUPED, AND IT HAS TO BE.
             |
             | It used to be `where(published)->orWhere(status != published)` at
             | the top level. Append any further condition after that and SQL
             | precedence reads the whole thing as
             | «published OR (draft AND course-match)» — so every published exam
             | in the workspace escapes the course filter, for the one reader who
             | also holds EXAMS_VIEW. A student never reaches the OR, so a student
             | fixture cannot see it.
             */
            ->where(fn ($q) => $q
                ->where('status', 'published')
                ->when($manages, fn ($inner) => $inner->orWhere('status', '!=', 'published')))
            /*
             | ⚠️ MATCHED THROUGH THE RELATION, SO AN UNKNOWN UUID MATCHES NOTHING.
             | The `ClassSessionController@index` idiom, character for character,
             | and for its reasons: no `exists:` rule (Laravel's is a raw query
             | with no tenant condition, and would confirm a guessed uuid before
             | any policy runs), and an unresolvable value returns an EMPTY list
             | rather than the unfiltered one — a tab headed «اختبارات هذه المادّة»
             | that silently drops its filter shows every paper on the platform.
             */
            ->when(
                $request->query('course'),
                fn ($q, $uuid) => $q->whereHas('course', fn ($course) => $course->where('uuid', $uuid)),
            )
            ->withCount('questions')
            /*
             | The reader's own attempts, in one load rather than one per card —
             | FR-017 asks the tab to show results beside each exam, and a
             | Resource runs once per row.
             |
             | ⚠️ NARROWED EXACTLY AS `StartAttempt::guardAttemptLimit()` IS, and
             | no further. That Action counts non-practice attempts and says
             | nothing about submission, so an abandoned in-progress sitting
             | spends a chance — filtering `submitted_at` here would show a
             | student «لك محاولة باقية» beside a button the server refuses. The
             | Resource splits the two questions instead: the COUNT is the
             | Action's number, and the score is read from submitted rows.
             |
             | Practice IS excluded, for the Action's own reason: a revision run
             | must not eat a graded chance (FR-026أ).
             */
            ->with(['attempts' => fn ($q) => $q
                ->where('student_user_id', $user->getKey())
                ->where('is_practice', false)])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json(ExamResource::collection($exams));
    }

    public function show(Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        return response()->json(ExamResource::make($exam->loadCount('questions')));
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $exam = Exam::create(array_merge($request->validated(), [
            'workspace_id' => app(WorkspaceContext::class)->id(),
        ]));

        return response()->json(ExamResource::make($exam), 201);
    }

    public function update(UpdateExamRequest $request, Exam $exam): JsonResponse
    {
        $exam->update($request->validated());

        return response()->json(ExamResource::make($exam->fresh()));
    }

    public function publish(Exam $exam, PublishExam $action): JsonResponse
    {
        $this->authorize('publish', $exam);

        return response()->json(ExamResource::make($action->handle($exam)));
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $this->authorize('delete', $exam);

        $exam->delete();

        return response()->json(null, 204);
    }
}
