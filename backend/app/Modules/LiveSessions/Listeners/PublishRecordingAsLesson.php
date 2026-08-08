<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Media\Events\MediaAssetReady;

/**
 * Turns a ready recording into a lesson.
 *
 * MediaAssetReady has existed since spec 004 with a comment saying the next
 * phase would listen for it. This is that listener.
 *
 * Publishing it as an ordinary Lesson is the point: every protection built in
 * 004 — the short-lived grant, the watermark, the renewal loop, the download
 * refusal — applies without a line of new security code. What differs is who may
 * watch, and that is one column (`class_session_id`) plus one route in
 * IssuePlaybackGrant, rather than a second player with a second guard that
 * drifts from the first at the first fix.
 */
class PublishRecordingAsLesson
{
    private const RECORDINGS_TITLE = 'تسجيلات الحصص';

    public function handle(MediaAssetReady $event): void
    {
        $asset = $event->asset;

        if ($asset->owner_type !== ClassSession::class) {
            return;
        }

        $session = ClassSession::query()->withoutWorkspaceScope()->find($asset->owner_id);

        if ($session === null) {
            return;
        }

        if ($session->course_id === null) {
            // A session tied to a subject alone has no course tree to live in,
            // and a lesson is a node in one. Recorded, not published — the
            // teacher is told and can place it themselves, which is better than
            // inventing a course nobody asked for.
            $session->forceFill(['recording_status' => 'no_course'])->save();

            return;
        }

        $lesson = $this->lessonFor($session);
        $placement = $lesson->exists
            // Either the recording's own lesson from a previous ingest, or the
            // `live_session` item the teacher placed — both already sit where
            // they belong, and rewriting their parent would move them.
            ? []
            : $this->recordingsChapterPlacement($session);

        $lesson->fill([
            'workspace_id' => $session->workspace_id,
            'course_id' => $session->course_id,
            ...$placement,
            // A title the teacher typed into their own sequence survives. Only a
            // row this listener created gets the generated one — renaming
            // "الحصة الثالثة: المشتقات" to "تسجيل: …" behind their back is a
            // background job editing their course.
            'title' => $lesson->exists ? $lesson->title : 'تسجيل: '.$session->title,
            'type' => 'video',
            // The item stops being a placeholder for a session and becomes the
            // recording itself, so what it pointed at is no longer true. Left
            // set, the reference-integrity filter would keep judging this video
            // against a `class_sessions` row (FR-047أ).
            'reference_id' => null,
            'class_session_id' => $session->getKey(),
            // Published explicitly. Since 016 a new lesson defaults to draft, and
            // a recording that lands as a draft is a recording nobody can watch —
            // a silent break in a shipped feature.
            'status' => ContentStatus::Published,
            // No `order` written here any more. It used to be 0 for every
            // recording, so a course with two recorded sessions had two lessons
            // claiming the same position — which the unique index now forbids
            // and which made sequential access undefined before it did.
            // HasSiblingOrder appends.
            'duration_seconds' => $asset->duration_seconds ?? 0,
            // Never free and never preview: those two flags bypass entitlement
            // entirely, and this recording answers to a seat (FR-030).
            'is_preview' => false,
            'is_free' => false,
        ])->save();

        $asset->forceFill([
            'owner_type' => Lesson::class,
            'owner_id' => $lesson->getKey(),
        ])->save();

        $session->forceFill(['recording_status' => 'published'])->save();
    }

    /**
     * The row this recording becomes — in the order that keeps one session to
     * one item (`FR-047أ` · SC-020).
     *
     * **(1) The recording's own lesson**, by `class_session_id`. Idempotence
     * first: a re-ingested recording must update the lesson it already produced
     * rather than leave the class with two copies of the same hour.
     *
     * **(2) The `live_session` item the teacher placed**, by `reference_id`.
     * This is the case 016 adds: the teacher put the session in the middle of
     * their sequence, so the recording belongs THERE and not appended at the end
     * of the tree, where it would be a second row for one session.
     *
     * The order between those two is load-bearing. Reversed, a course where a
     * recording was appended before the teacher created an item would convert the
     * item as well and end up with both — the exact duplication SC-020 forbids.
     *
     * **(3) A new row**, placed in the recordings chapter as 005 always did.
     */
    private function lessonFor(ClassSession $session): Lesson
    {
        $own = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->first();

        if ($own !== null) {
            return $own;
        }

        $placed = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $session->course_id)
            ->where('type', LessonType::LiveSession->value)
            ->where('reference_id', $session->getKey())
            ->orderBy('id')
            ->first();

        return $placed ?? new Lesson;
    }

    /**
     * Where a recording with no item of its own goes.
     *
     * @return array{section_id: int, chapter_id: int}
     */
    private function recordingsChapterPlacement(ClassSession $session): array
    {
        $chapter = $this->recordingsChapter($session);

        return [
            'section_id' => (int) $chapter->section_id,
            'chapter_id' => (int) $chapter->getKey(),
        ];
    }

    /**
     * The course's "session recordings" chapter, created the first time it is
     * needed.
     *
     * One place per course rather than one per session: a course with thirty
     * taught sessions would otherwise grow thirty single-lesson sections, and a
     * student looking for last Tuesday would have to scroll past every other
     * Tuesday to find it.
     */
    private function recordingsChapter(ClassSession $session): Chapter
    {
        $section = Section::query()
            ->withoutWorkspaceScope()
            ->firstOrCreate(
                [
                    'course_id' => $session->course_id,
                    'title' => self::RECORDINGS_TITLE,
                ],
                [
                    'workspace_id' => $session->workspace_id,
                    // Last in the tree: the recordings follow the taught
                    // material rather than pushing it down. Appended rather than
                    // pinned to a magic 999, which a unique index would let
                    // exactly one section per course hold.
                    'status' => ContentStatus::Published,
                ],
            );

        return Chapter::query()
            ->withoutWorkspaceScope()
            ->firstOrCreate(
                [
                    'section_id' => $section->getKey(),
                    'title' => self::RECORDINGS_TITLE,
                ],
                [
                    'workspace_id' => $session->workspace_id,
                    'course_id' => $session->course_id,
                    'status' => ContentStatus::Published,
                ],
            );
    }
}
