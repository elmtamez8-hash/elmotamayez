<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use Illuminate\Http\JsonResponse;

/**
 * What a reference item may be pointed at, for the one course being authored.
 *
 * One endpoint rather than filters bolted onto `/exams` and `/class-sessions`.
 * The pickers need exactly two lists, both narrowed to this course, and both
 * authorised by the same permission that opened the editor — `manageLessons` on
 * the course. Two paginated indexes in two other modules would have to grow a
 * course filter each, and the second page of either would silently hide a target
 * from a dropdown.
 *
 * Published exams only (`FR-040`): placing a draft exam gives the student a door
 * that opens onto nothing, and in a sequential course a door that never opens.
 */
class ReferenceTargetController extends Controller
{
    public function index(Course $course): JsonResponse
    {
        $this->authorize('manageLessons', $course);

        $exams = Exam::query()
            ->where('course_id', $course->getKey())
            ->where('status', 'published')
            // Counted in the one query, not per row inside the map — the N+1 by
            // construction CLAUDE.md names.
            ->withCount('questions')
            ->orderBy('title')
            ->get()
            ->map(fn (Exam $exam): array => [
                'uuid' => $exam->uuid,
                'title' => $exam->title,
                'passing_score' => $exam->passing_score,
                'questions_count' => (int) $exam->questions_count,
            ]);

        // Declared with the time rather than assumed by the client: rendering a
        // session in whatever zone the laptop is set to shows the same class at a
        // different hour to different people (005 SC-016).
        $timezone = app(SessionSettings::class)->timezone();

        $sessions = ClassSession::query()
            ->where('course_id', $course->getKey())
            // Cancelled sessions excluded: a placeholder for a class that will
            // not happen is a dead row a student cannot be told anything useful
            // about. Past ones stay — the teacher may be placing an item for a
            // session whose recording is still being ingested.
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get()
            ->map(fn (ClassSession $session): array => [
                'uuid' => $session->uuid,
                'title' => $session->title,
                'starts_at' => $session->starts_at,
                'timezone' => $timezone,
                'status' => $session->status->value,
                'status_label' => $session->status->label(),
                'has_recording' => $session->recording_status === 'published',
            ]);

        return response()->json([
            'exams' => $exams->values()->all(),
            'sessions' => $sessions->values()->all(),
        ]);
    }
}
