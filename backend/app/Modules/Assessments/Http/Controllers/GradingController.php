<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\GradeEssayAnswer;
use App\Modules\Assessments\Actions\ReviseGrade;
use App\Modules\Assessments\Actions\SaveRubric;
use App\Modules\Assessments\Http\Requests\GradeAnswerRequest;
use App\Modules\Assessments\Http\Requests\ReviseGradeRequest;
use App\Modules\Assessments\Http\Requests\SaveRubricRequest;
use App\Modules\Assessments\Http\Resources\GradingAnswerResource;
use App\Modules\Assessments\Http\Resources\GradingQueueResource;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Support\GradingSettings;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The grading board: what is waiting, and what one person does about it.
 *
 * ⚠️ EVERY RELATION THIS SCREEN READS IS EAGER-LOADED, AND THAT IS A BUDGET
 * RATHER THAN A HABIT. The queue is 500 rows on the first day of a term, and a
 * Resource runs once per row — the exam title, the student's name and the count
 * of unmarked answers would be 1,500 queries on one page load. `pending_count`
 * comes from a subquery in the list query for the same reason.
 */
class GradingController extends Controller
{
    use LogsActivity;

    public function queue(Request $request, GradingSettings $settings): JsonResponse
    {
        $this->authorizeGrading($request);

        $anonymous = $this->anonymous($settings);

        $query = Attempt::query()
            ->where('status', Attempt::STATUS_PENDING_GRADING)
            /*
             | ⚠️ A PRACTICE PAPER NEVER ENTERS THE QUEUE. Both generators refuse
             | essays today, so this filter matches nothing — but `StartAttempt`
             | takes `isPractice: true` against a real exam, and that paper WOULD
             | carry one. A teacher's queue filling with revision papers students
             | set themselves is a queue nobody keeps clear.
             */
            ->where('is_practice', false)
            ->withCount(['answers as pending_answers_count' => fn (Builder $answers): Builder => $answers
                ->where('requires_grading', true)
                ->whereNull('graded_at')])
            ->with('exam:id,uuid,title')
            // Oldest first: a queue ordered any other way leaves the paper that
            // has waited longest waiting longest (FR-027).
            ->orderBy('submitted_at');

        // Not loaded at all when anonymity is on — a relation fetched and then
        // dropped in the Resource is a name that travelled anyway.
        if (! $anonymous) {
            $query->with('student:id,uuid,first_name,last_name');
        }

        $examUuid = $request->string('exam')->toString();

        if ($examUuid !== '') {
            $query->whereHas('exam', fn (Builder $exam): Builder => $exam->where('uuid', $examUuid));
        }

        $page = $query->paginate(min(50, max(5, (int) $request->integer('per_page', 20))));

        return response()->json([
            'data' => array_map(
                fn (Attempt $attempt): GradingQueueResource => new GradingQueueResource($attempt, $anonymous),
                $page->items(),
            ),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** One paper, opened to be marked. */
    public function show(Request $request, Attempt $attempt, GradingSettings $settings): JsonResponse
    {
        $this->authorizeGrading($request);
        $this->authorize('view', $attempt);

        $answers = $attempt->answers()
            ->where('requires_grading', true)
            ->with(['question:id,uuid,content,explanation,points', 'question.rubricCriteria', 'gradingRecords'])
            ->orderBy('id')
            ->get();

        // The snapshot's points, read once for the whole paper rather than once
        // per answer inside the Resource.
        $ceilings = AttemptItem::query()
            ->where('attempt_id', $attempt->getKey())
            ->pluck('points', 'question_id');

        foreach ($answers as $answer) {
            $answer->setAttribute('points_possible', $ceilings[$answer->question_id] ?? 0);
        }

        $anonymous = $this->anonymous($settings);
        $student = $anonymous ? null : $attempt->student;

        return response()->json([
            'data' => [
                'uuid' => $attempt->uuid,
                'exam_title' => $attempt->exam?->title,
                'submitted_at' => $attempt->submitted_at,
                'status' => $attempt->status,
                'auto_score' => (float) $attempt->score,
                'is_anonymous' => $anonymous,
                'student' => $student === null ? null : ['uuid' => $student->uuid, 'name' => $student->name],
                'answers' => GradingAnswerResource::collection($answers),
            ],
        ]);
    }

    public function grade(GradeAnswerRequest $request, Answer $answer, GradeEssayAnswer $action): JsonResponse
    {
        $this->authorize('perform', $answer);

        try {
            $graded = $action->handle($answer, $this->currentUser($request), $this->marks($request));
        } catch (DomainException $exception) {
            // Includes the claim conflict, which is not a field error: the
            // message says somebody else marked it, and the screen reloads the
            // paper rather than asking the grader to correct anything.
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => GradingAnswerResource::make($graded)]);
    }

    public function revise(ReviseGradeRequest $request, Answer $answer, ReviseGrade $action): JsonResponse
    {
        $this->authorize('revise', $answer);

        try {
            $revised = $action->handle(
                $answer,
                $this->currentUser($request),
                $this->marks($request),
                $request->string('reason')->toString(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => GradingAnswerResource::make($revised)]);
    }

    /** The mark scheme for one essay question (FR-028). */
    public function saveRubric(SaveRubricRequest $request, Question $question, SaveRubric $action): JsonResponse
    {
        $this->authorize('update', $question);

        /** @var array<int, array{label: string, max_points: float|int|string, order?: int}> $criteria */
        $criteria = $request->validated('criteria');

        try {
            $saved = $action->handle($question, $criteria);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $saved->map(fn ($criterion): array => [
                'id' => (int) $criterion->getKey(),
                'label' => $criterion->label,
                'max_points' => (float) $criterion->max_points,
            ])->all(),
        ]);
    }

    /**
     * Turn student names on or off for the whole workspace (FR-033).
     *
     * ⚠️ TURNING IT OFF IS AN AUDITED ACT. Anonymity is a promise made to
     * students; the moment it is withdrawn is the moment worth being able to
     * point at afterwards, and a setting that flips with no trace cannot be
     * asked about.
     */
    public function updateSettings(Request $request, GradingSettings $settings): JsonResponse
    {
        $user = $this->currentUser($request);

        abort_unless($user->can(Permissions::SETTINGS_UPDATE), 403);

        $validated = $request->validate(['anonymous' => ['required', 'boolean']]);
        $workspace = app(WorkspaceContext::class)->current();

        abort_if($workspace === null, 422, 'اختر مساحة عمل أولاً.');

        $was = $settings->isAnonymous($workspace);
        $now = (bool) $validated['anonymous'];

        $settings->setAnonymous($workspace, $now);

        if ($was && ! $now) {
            $this->logActivity('grading.anonymity.disabled', $workspace, [
                'setting' => 'grading.anonymous',
                'from' => true,
                'to' => false,
            ]);
        }

        return response()->json(['data' => ['anonymous' => $now]]);
    }

    /**
     * @return array<int, array{criterion_id?: int|null, points: float|int|string, comment?: string|null}>
     */
    private function marks(FormRequest $request): array
    {
        /** @var array<int, array{criterion_id?: int|null, points: float|int|string, comment?: string|null}> $marks */
        $marks = $request->validated('marks');

        return $marks;
    }

    private function anonymous(GradingSettings $settings): bool
    {
        $workspace = app(WorkspaceContext::class)->current();

        return $workspace !== null && $settings->isAnonymous($workspace);
    }

    /**
     * The queue itself is gated on being able to grade at all — a reader with
     * `attempts.view_all` and no grading permission has no business holding a
     * list of unmarked papers, which is a list of who has not been dealt with.
     */
    private function authorizeGrading(Request $request): void
    {
        abort_unless($this->currentUser($request)->can(Permissions::GRADING_PERFORM), 403);
    }
}
