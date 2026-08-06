<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

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

        $chapter = $this->recordingsChapter($session);

        // Idempotent: a re-ingested recording updates its lesson rather than
        // leaving the class with two copies of the same hour.
        $lesson = Lesson::query()
            ->withoutWorkspaceScope()
            ->firstOrNew(['class_session_id' => $session->getKey()]);

        $lesson->fill([
            'workspace_id' => $session->workspace_id,
            'course_id' => $session->course_id,
            'section_id' => $chapter->section_id,
            'chapter_id' => $chapter->getKey(),
            'title' => 'تسجيل: '.$session->title,
            'type' => 'video',
            'order' => 0,
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
                    // material rather than pushing it down.
                    'order' => 999,
                    'is_published' => true,
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
                    'order' => 1,
                ],
            );
    }
}
