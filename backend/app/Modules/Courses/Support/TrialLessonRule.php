<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Which lesson may be a course's «حصة تجريبية», and is it showable now?» —
 * spec 040, owner decisions 2026-10-09.
 *
 * TWO FACES, ONE LIST OF CONDITIONS. `refusalFor()` answers the teacher marking
 * a lesson (with the reason, in Arabic); `scopeEligible()` is the SQL every
 * public reader applies — the teacher page, the course card, and the guest door
 * that streams the bytes. If the two disagreed, the page would advertise a trial
 * the door refuses (a dead link on the main button), or the door would serve a
 * lesson the teacher was told they could not mark. `TrialLessonRuleTest` proves
 * they agree on every refusal case.
 *
 * ⚠️ NOT `Lesson::isOpen()` and NOT `ReadPublicPreviewLesson::readable()`. The
 * «open» marks mean «any SIGNED-IN account» on an uploaded lesson, and teachers
 * set them meaning exactly that; the trial is a separate, explicit choice.
 *
 * ⚠️ UPLOADED VIDEO ONLY ON A PROVIDER LISTED IN `media.trial_providers` — the
 * streaming CDN, which the guest door can redirect to with a short-lived signed
 * URL and never serve bytes itself. A legacy asset on the local provider would
 * need PHP to stream every Range request to anonymous visitors, through every
 * limiter, on PHP-FPM workers — so it is refused, and the teacher is told to
 * upload it again (design review M2). Named in config and not here because the
 * provider's name lives in its adapter alone (`ProviderNameContainmentTest`).
 */
final class TrialLessonRule
{
    /**
     * Why `$lesson` cannot be `$course`'s trial — or null when it can.
     *
     * Asked at MARKING. Publication and readiness are deliberately not asked
     * here: a teacher may pick a lesson before publishing it, and the readers
     * decide what is shown (`scopeEligible()`).
     */
    public static function refusalFor(Course $course, Lesson $lesson): ?string
    {
        if ((int) $lesson->course_id !== (int) $course->getKey()) {
            return 'اختر درساً من هذا الكورس.';
        }

        if (! in_array($lesson->type, [LessonType::Embed->value, LessonType::Video->value], true)) {
            return 'الحصة التجريبية فيديو: مضمَّن من يوتيوب أو فيميو، أو مرفوع على المنصة.';
        }

        if ($lesson->class_session_id !== null) {
            return 'تسجيلات الحصص المباشرة لا تكون حصة تجريبية.';
        }

        if ($lesson->release_session_id !== null || self::isCohortScoped($lesson)) {
            return 'هذا الدرس مقصور على مجموعة أو حصة؛ اختر درساً لكل الطلاب.';
        }

        if ((bool) $lesson->is_high_value) {
            return 'الدروس المعلَّمة عالية القيمة لا تكون حصة تجريبية.';
        }

        if ($lesson->type === LessonType::Video->value) {
            $asset = $lesson->mediaAsset;

            if (! $asset instanceof MediaAsset || $asset->kind !== MediaKind::Video) {
                return 'ارفع فيديو هذا الدرس أولاً.';
            }

            if (! in_array($asset->provider, self::providers(), true)) {
                return 'هذا الفيديو مخزَّن بالطريقة القديمة؛ ارفعه من جديد ليصلح حصة تجريبية.';
            }
        }

        return null;
    }

    /**
     * The same conditions as SQL, plus what may change after marking:
     * publication of the lesson, its chapter and its section, and — for an
     * uploaded video — that the asset is ready to play.
     *
     * The COURSE is not asked here (listed, not deleted, public): each reader
     * already resolved it through `publiclyListed()`, and asking again would be
     * the second spelling of «is this course public».
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public static function scopeEligible(Builder $query): Builder
    {
        return $query
            ->visibleToStudents()
            ->whereNull('lessons.class_session_id')
            ->whereNull('lessons.release_session_id')
            ->where('lessons.is_high_value', false)
            ->whereNotExists(fn ($scopes) => $scopes
                ->from((new LessonCohortScope)->getTable())
                ->whereColumn('lesson_id', 'lessons.id'))
            // ⚠️ GROUPED: a bare `orWhere` at this level would hand every lesson
            // above it to any visitor.
            ->where(fn ($kind) => $kind
                ->where('lessons.type', LessonType::Embed->value)
                ->orWhere(fn ($video) => $video
                    ->where('lessons.type', LessonType::Video->value)
                    ->whereHas('mediaAsset', fn ($asset) => $asset
                        ->where('kind', MediaKind::Video->value)
                        ->whereIn('provider', self::providers())
                        ->where('status', MediaAssetStatus::Ready->value))));
    }

    /**
     * What the teacher is told about their pick: `visible` when visitors see it
     * now, otherwise why not yet — `unpublished` (the lesson, its chapter or
     * section, or the course itself is not out), `processing` (the upload is
     * still being prepared), `unavailable` (it stopped qualifying after marking).
     * Null when the course has no trial.
     */
    public static function statusFor(Course $course): ?string
    {
        $lesson = $course->trial_lesson_id === null
            ? null
            : Lesson::query()->withoutWorkspaceScope()->with('mediaAsset')->find($course->trial_lesson_id);

        if (! $lesson instanceof Lesson) {
            return null;
        }

        if (self::scopeEligible(Lesson::query()->withoutWorkspaceScope()->whereKey($lesson->getKey()))->exists()) {
            return Course::query()->withoutWorkspaceScope()->publiclyListed()->whereKey($course->getKey())->exists()
                ? 'visible'
                : 'unpublished';
        }

        if (self::refusalFor($course, $lesson) !== null) {
            return 'unavailable';
        }

        return $lesson->type === LessonType::Video->value && $lesson->mediaAsset?->status !== MediaAssetStatus::Ready
            ? 'processing'
            : 'unpublished';
    }

    /** @return list<string> */
    private static function providers(): array
    {
        $providers = config('media.trial_providers', []);

        return is_array($providers)
            ? array_values(array_filter($providers, 'is_string'))
            : [];
    }

    private static function isCohortScoped(Lesson $lesson): bool
    {
        return LessonCohortScope::query()
            // Same workspace as the lesson; declared so a reader resolved to
            // another workspace cannot read «not scoped» from an empty answer.
            ->withoutWorkspaceScope()
            ->where('lesson_id', $lesson->getKey())
            ->exists();
    }
}
