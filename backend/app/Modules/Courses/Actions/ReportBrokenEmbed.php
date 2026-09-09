<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Models\User;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A viewer says an embedded lesson's video does not work (032 · US3).
 *
 * ⚠️ IN `Courses` AND NOT IN `Marketplace`, EVEN THOUGH THE ROUTE IS THERE.
 * `link_reported_at` is a `lessons` column and two other Actions in this module
 * clear it; one column with writers in two modules is the coupling Principle III
 * exists to refuse. A route is an address, not an owner.
 *
 * ⚠️ AND IT ANSWERS NOTHING. Whatever it finds — a lesson that is not there, one
 * that is locked, one already reported this hour — the caller gets the same 202.
 * A door that varied its reply by what it found would be an oracle answering
 * questions about the whole tree, which is why this returns `void`.
 */
class ReportBrokenEmbed extends Action
{
    /** One alert per lesson inside this window. */
    private const WINDOW_HOURS = 24;

    /** One report per address per WORKSPACE per hour — see `withinWorkspaceBudget()`. */
    private const PER_WORKSPACE_PER_HOUR = 1;

    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly WorkspaceContext $context,
    ) {}

    public function handle(string $courseKey, string $lessonUuid, string $reporterFingerprint): void
    {
        $course = $this->resolveCourse($courseKey);

        if ($course === null) {
            return;
        }

        $lesson = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $course->getKey())
            ->where('type', LessonType::Embed->value)
            /*
            | ⚠️ WIDER THAN THE READING DOOR ON PURPOSE, AND NARROWER IN ONE
            | RESPECT.
            |
            | Wider: there is deliberately NO `publiclyListed()` here. The
            | ENROLLED student sees the break too, from `/learn/lessons/…`, and
            | their teacher may not be listed in the marketplace at all —
            | restricting this door to listed teachers would drop that report in
            | silence, for ever, invisibly from both sides (FR-021). The door
            | gives nothing away however wide it is: its answer never changes.
            |
            | Narrower: `visibleToStudents()`, so a draft item nobody can open is
            | not reportable.
            */
            ->visibleToStudents()
            ->where('uuid', $lessonUuid)
            ->first();

        if ($lesson === null) {
            return;
        }

        if (! $this->withinWorkspaceBudget($course, $reporterFingerprint)) {
            return;
        }

        $now = CarbonImmutable::now();
        $windowStart = $now->subHours(self::WINDOW_HOURS);

        /*
        | ⚠️ THE CLAIM IS THE STAMP — ONE CONDITIONAL UPDATE, NEVER A READ THEN A
        | WRITE. Two viewers pressing the button in the same second both read
        | null and both send; InnoDB locks the primary-key row so the second
        | matches nothing, and SQLite serialises on the file. One alert, proved
        | rather than hoped for.
        |
        | The timestamp is COMPUTED, not `NOW()` in the predicate, and no function
        | wraps a column — so this is a primary-key lookup, which is also why
        | `link_reported_at` carries no index: a secondary index here would be
        | pure write cost.
        */
        $claimed = DB::table('lessons')
            ->where('id', $lesson->getKey())
            ->where(function ($query) use ($windowStart): void {
                $query->whereNull('link_reported_at')
                    ->orWhere('link_reported_at', '<', $windowStart);
            })
            ->update(['link_reported_at' => $now]);

        if ($claimed === 1) {
            // Recorded on this path too, or the very same browser would qualify
            // as «a fingerprint not seen» on its next attempt and spend the
            // second slot itself.
            $this->rememberReporter($lesson, $reporterFingerprint, $now);
            $this->alertContentHolders($course, $lesson);

            return;
        }

        /*
        | ⛔ THE WINDOW IS SPENT — AND A SINGLE FALSE PRESS MUST NOT BE ABLE TO
        | SPEND IT.
        |
        | The stamp is written by a STRANGER WITH NO ACCOUNT. Its precedent
        | `notified_dormant_at` is stamped by us, about us; here the sender is a
        | potential adversary, and one malicious tap would otherwise silence every
        | honest report for twenty-four hours — turning off the only sensor there
        | is, from outside.
        |
        | So a fingerprint not seen inside the window sends a SECOND and final
        | alert; the same fingerprint sends nothing. A hundred presses from one
        | browser are one alert; the next real witness still gets through.
        |
        | ⚠️ IN THE CACHE, NEVER A COLUMN. A stored address hash is personal data:
        | a `data_categories` row, an export path, an erasure path and a retention
        | sweep — all for a number that means nothing after a day. This key
        | expires by itself and there is nothing to sweep.
        */
        if (! $this->rememberReporter($lesson, $reporterFingerprint, $now)) {
            // The same browser again. A hundred presses are one alert.
            return;
        }

        /*
        | ⚠️ «A SECOND AND FINAL» IS A SLOT, NOT A PROPERTY OF THE FINGERPRINT.
        | Without this claim every NEW address would qualify, and one broken
        | lesson in front of a class of thirty would produce thirty alerts — the
        | flood the window exists to prevent, arriving through the door opened to
        | stop a false report silencing the honest one. Claimed atomically, so
        | two new witnesses in the same second do not both win it.
        */
        if (! Cache::add(
            "embed-report-second:{$lesson->getKey()}",
            true,
            $now->addHours(self::WINDOW_HOURS),
        )) {
            return;
        }

        $this->alertContentHolders($course, $lesson);
    }

    /**
     * True when this fingerprint had not been seen for this lesson inside the
     * window — and false for every repeat.
     *
     * ⚠️ IN THE CACHE, NEVER A COLUMN. A stored address hash is personal data: a
     * `data_categories` row, an export path, an erasure path and a retention
     * sweep, all for a number that means nothing after a day. This key expires
     * by itself and there is nothing to sweep.
     */
    private function rememberReporter(Lesson $lesson, string $fingerprint, CarbonImmutable $now): bool
    {
        return Cache::add(
            "embed-report:{$lesson->getKey()}:{$fingerprint}",
            true,
            $now->addHours(self::WINDOW_HOURS),
        );
    }

    /**
     * ⚠️ NO `publiclyListed()` HERE EITHER, and `withoutWorkspaceScope()` because
     * the caller is a guest whose context is null — declared rather than relied
     * on, for the reader signed in elsewhere.
     */
    private function resolveCourse(string $key): ?Course
    {
        return Course::query()
            ->withoutWorkspaceScope()
            ->where('status', ContentStatus::Published->value)
            // Grouped, or the OR escapes the status condition above it and
            // reports become possible against every draft on the platform.
            ->where(fn ($query) => $query->where('slug', $key)->orWhere('uuid', $key))
            ->orderByRaw('CASE WHEN slug = ? THEN 0 ELSE 1 END', [$key])
            ->first();
    }

    /**
     * A second limit, per WORKSPACE rather than per lesson.
     *
     * ⚠️ `throttle:public` IS SIXTY A MINUTE BY ADDRESS AND THE PER-LESSON WINDOW
     * IS PER LESSON — so one address may legitimately report sixty DIFFERENT
     * lessons a minute. «One message a day» is true of a lesson and false of a
     * platform, and the teacher whose bell fills up is the one this feature
     * exists to help.
     *
     * ⚠️ INSIDE THE ACTION, NOT AS ROUTE MIDDLEWARE: the workspace is unknown
     * until the course resolves. And it changes NOTHING about the response — it
     * skips the effect and nothing else, because a door whose reply varied by
     * what it found would be the oracle this whole contract refuses to be.
     */
    private function withinWorkspaceBudget(Course $course, string $fingerprint): bool
    {
        return RateLimiter::attempt(
            "embed-report:{$fingerprint}:{$course->workspace_id}",
            self::PER_WORKSPACE_PER_HOUR,
            static fn (): bool => true,
            3600,
        ) !== false;
    }

    /**
     * The teacher and whoever helps them with this course's content (FR-020).
     *
     * ⛔ `can()` FROM A GUEST REQUEST ANSWERS «NO» ABOUT EVERYBODY. spatie runs in
     * team mode and the team id comes from `WorkspaceContext` alone — a guest's
     * context is null, so there are no roles and no permissions for anyone, and a
     * fan-out written any other way reaches NOBODY, silently, behind a constant
     * 202.
     *
     * `forWorkspace()` is the one sanctioned mechanism: it sets the context AND
     * spatie's team id and restores both. ⚠️ `WorkspaceContext::set()` is
     * forbidden here — it is an application-wide singleton that would leak this
     * workspace into whatever the same worker handles next.
     *
     * `assistant-teacher` holds `courses.update`, so «المدرّس ومن يعينه» is
     * satisfied by the permission rather than by a list of role names.
     *
     * ⚠️ NOT NAMED `notify()`. `ProviderAgnosticTest` fails the build on `->notify(`
     * anywhere under `Actions/`, because that is Laravel's own Notifiable method
     * and using it would bypass `DispatchNotification` — the one door where the
     * channel is chosen from the recipient's preferences. A private helper with
     * that name is indistinguishable from the banned call to the grep that
     * enforces the rule, and the rule's whole value is that nobody can read past
     * it.
     */
    private function alertContentHolders(Course $course, Lesson $lesson): void
    {
        $this->context->forWorkspace($course->workspace_id, function () use ($course, $lesson): void {
            /** @var iterable<int, User> $recipients */
            $recipients = User::query()->permission(Permissions::COURSES_UPDATE)->get();

            foreach ($recipients as $recipient) {
                $this->dispatch->handle(new NotificationRequest(
                    recipient: $recipient,
                    type: NotificationType::LessonLinkReported,
                    variables: [
                        'lesson_title' => (string) $lesson->title,
                        'course_title' => (string) $course->title,
                    ],
                    actionUrl: '/manage/courses/'.$course->uuid.'/content?lesson='.$lesson->uuid,
                    workspaceId: (int) $course->workspace_id,
                ));
            }
        });
    }
}
