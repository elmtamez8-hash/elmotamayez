<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Http\Requests\SubmitAttemptRequest;
use App\Modules\Assessments\Http\Resources\AttemptResource;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Exam;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttemptController extends Controller
{
    public function start(Request $request, string $examUuid, StartAttempt $action): JsonResponse
    {
        $exam = Exam::forStudentDoor($examUuid);

        $this->authorize('view', $exam);

        if (! $exam->isPublished()) {
            return response()->json(['message' => 'Exam is not available.'], 422);
        }

        // A used-up attempt allowance throws DomainException, rendered as 422.
        $attempt = $action->handle($exam, $this->currentUser($request));

        /*
        | ⛔ THE PAPER IS RENDERED FROM THE SNAPSHOT IT IS MARKED AGAINST (QA
        | 2026-10-29). This read the LIVE question, while `AnswerMarker` marks
        | against `attempt_items.snapshot`: a teacher edited a question while a
        | student had the paper open, the resume showed the new wording and the
        | re-ordered options, and the student picked what was now correct and
        | scored zero. A pre-snapshot item falls back to the live row.
        */
        $snapshots = $attempt->items()->get(['question_id', 'snapshot'])
            ->keyBy(fn (AttemptItem $item): int => (int) $item->question_id);

        $questions = $action->questionsForAttempt($attempt)->map(function ($q) use ($action, $attempt, $snapshots) {
            $snapshot = $snapshots->get((int) $q->id)?->snapshot;
            $frozen = is_array($snapshot['options'] ?? null);

            $options = $frozen
                ? collect($snapshot['options'])->map(fn (array $o): array => ['id' => (int) $o['id'], 'content' => (string) $o['content']])
                : collect($q->options)->map(fn ($o): array => ['id' => (int) $o->id, 'content' => (string) $o->content]);

            return [
                'id' => $q->id,
                'type' => $frozen ? ($snapshot['type'] ?? $q->type) : $q->type,
                'content' => $frozen ? ($snapshot['content'] ?? $q->content) : $q->content,
                'points' => $q->points,
                'options' => $action->optionsForQuestion($attempt, $options->values(), (int) $q->id),
            ];
        });

        return response()->json([
            'attempt' => AttemptResource::make($attempt),
            'questions' => $questions,
        ], 201);
    }

    public function submit(SubmitAttemptRequest $request, string $attemptUuid, GradeAttempt $action): JsonResponse
    {
        $attempt = Attempt::forStudentDoor($attemptUuid);

        $this->authorize('submit', $attempt);

        /*
         | ⚠️ THE "ALREADY SUBMITTED" CHECK MOVED INTO THE ACTION, and it is not a
         | tidying. Reading `isGraded()` here and writing there is a read followed
         | by a write — two taps on a flaky connection both pass this line and both
         | write the full answer set, which double-counts every mistake in the
         | notebook and doubles the denominator of every wrong_pct. The Action now
         | claims the attempt with one conditional UPDATE and throws when it loses.
         */
        try {
            $graded = $action->handle($attempt, $request->validated('answers'));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(AttemptResource::make($graded));
    }

    public function show(string $attemptUuid): JsonResponse
    {
        $attempt = Attempt::forStudentDoor($attemptUuid);

        $this->authorize('view', $attempt);

        return response()->json(AttemptResource::make($attempt->load('answers.question')));
    }
}
