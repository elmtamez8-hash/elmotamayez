<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;

/**
 * What a reference item is pointing AT, said in words.
 *
 * A reference item stores an id (R9), so on its own it can describe itself only
 * as "exam" — which is no use to the teacher choosing between four quizzes, and
 * no use at all to the student, who needs the date of the session or the mark the
 * quiz asks for.
 *
 * Returns null when the target is gone. That is not an error here: the student's
 * scopes never hand this an orphan, and the teacher's tree already marks one via
 * `ReferenceIntegrity::missingAmong()`. Two ways of saying "missing" would be two
 * places to keep in step.
 */
final class ReferenceSummary
{
    /**
     * @return array<string, mixed>|null
     */
    public static function for(Lesson $lesson): ?array
    {
        if ($lesson->reference_id === null) {
            return null;
        }

        return match (LessonType::from($lesson->type)) {
            LessonType::Exam => self::exam($lesson->reference_id),
            LessonType::LiveSession => self::session($lesson->reference_id),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private static function exam(int $id): ?array
    {
        $exam = Exam::query()->withoutWorkspaceScope()->find($id);

        if ($exam === null) {
            return null;
        }

        return [
            'uuid' => $exam->uuid,
            'title' => $exam->title,
            // The one number the gate means by "pass". Sent so the student is
            // told what they are being asked for rather than discovering it.
            'passing_score' => $exam->passing_score,
            'duration_minutes' => $exam->duration_minutes,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function session(int $id): ?array
    {
        $session = ClassSession::query()->withoutWorkspaceScope()->find($id);

        if ($session === null) {
            return null;
        }

        return [
            'uuid' => $session->uuid,
            'title' => $session->title,
            'starts_at' => $session->starts_at,
            // With the time, never assumed by the client (005 SC-016).
            'timezone' => app(SessionSettings::class)->timezone(),
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'state' => self::sessionState($session),
        ];
    }

    /**
     * The one word the screen switches on (FR-047 · FR-048).
     *
     * `upcoming` while there is something to wait for, `recorded` once the
     * recording is in the tree — and `unavailable` for the case the spec singles
     * out: a session whose time has passed with no recording ever published. Left
     * as `upcoming` that row would sit in a student's course forever, promising a
     * class that already happened without them.
     */
    private static function sessionState(ClassSession $session): string
    {
        if ($session->recording_status === 'published') {
            return 'recorded';
        }

        if ($session->status->value === 'cancelled') {
            return 'cancelled';
        }

        if ($session->ends_at->isFuture()) {
            return 'upcoming';
        }

        // Still being fetched or transcoded — a real state, and a different one
        // from "there will never be a recording".
        return in_array($session->recording_status, ['pending', 'ingesting'], true)
            ? 'processing'
            : 'unavailable';
    }
}
