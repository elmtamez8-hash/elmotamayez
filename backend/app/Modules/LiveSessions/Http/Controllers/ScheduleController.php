<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\GetStudentSchedule;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Http\Resources\ClassSessionResource;
use App\Modules\LiveSessions\Http\Resources\SessionBookingResource;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own timetable, across every teacher.
 *
 * No policy call: there is no resource to authorise against. The authorisation
 * IS the identity — the action only ever reads rows belonging to the
 * authenticated user, and there is no parameter by which to ask for anyone
 * else's.
 */
class ScheduleController extends Controller
{
    public function index(Request $request, GetStudentSchedule $action): JsonResponse
    {
        $bookings = $action->handle($this->currentUser($request));

        return response()->json(['data' => SessionBookingResource::collection($bookings)]);
    }

    public function next(Request $request, GetStudentSchedule $action): JsonResponse
    {
        $booking = $action->next($this->currentUser($request));

        $session = $booking?->classSession;

        if ($booking === null || $session === null) {
            // Not a 404. "You have no upcoming sessions" is an answer, and the
            // screen shows an empty state for it rather than an error (FR-055).
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => SessionBookingResource::make($booking),
            // Counted on the server. A countdown built from the browser's clock
            // shows a time that does not exist on a machine whose clock is off
            // (SC-016).
            'seconds_until_start' => max(0, (int) now()->diffInSeconds($session->starts_at, false)),
        ]);
    }

    /**
     * The next session of ONE course, for the header of its page (US2 · FR-015).
     *
     * ⚠️ NOT `next()` WITH A FILTER. That one reads the student's own BOOKINGS
     * across every teacher they study with — the right answer for a timetable and
     * the wrong shape for a course header, which must name the next lesson of
     * this course whether or not a seat has been taken yet. A student who has not
     * booked is exactly the student the header exists for.
     *
     * ⚠️ THE BOUND IS `ends_at`, NOT `starts_at`. Bounded by the start, the header
     * loses the session at the moment it begins — during the very minutes the
     * join button is the only thing on the page worth pressing. The window is
     * widened by the join allowance for the same reason `join_open` exists.
     *
     * ⚠️ AND `cancelled` IS EXCLUDED BY STATUS RATHER THAN BY THE WINDOW.
     * `CancelClassSession` does not stamp `room_closed_at`, so `joinWindowCovers()`
     * still says yes on a cancelled session — the 018 defect that sent a teacher
     * who had just cancelled a lesson to a raw error page.
     */
    public function nextForCourse(Request $request, Course $course, EnrollmentDirectory $enrollments): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $enrollments->hasActiveEnrollment($user, (int) $course->getKey())) {
            return $this->notEnrolled();
        }

        $window = app(SessionSettings::class)->joinWindowMinutes();

        $session = ClassSession::query()
            ->where('course_id', $course->getKey())
            ->whereIn('status', [ClassSessionStatus::Scheduled, ClassSessionStatus::Live])
            ->where('ends_at', '>=', now()->subMinutes($window))
            ->orderBy('starts_at')
            // The Resource asks every published session where its recording went,
            // and reads the viewer's booking off the loaded relation.
            ->with(['course', 'bookings', 'recordingLesson'])
            ->first();

        if ($session === null) {
            // Not a 404 and not an empty list. «لا حصّةَ قادمة» is an ANSWER, and
            // the header renders a sentence for it rather than an empty countdown
            // (FR-015).
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => ClassSessionResource::make($session),
            // Counted on the server, like `next()` above: a countdown built from
            // the browser's clock shows a time that does not exist on a machine
            // whose clock is off (SC-016).
            'seconds_until_start' => max(0, (int) now()->diffInSeconds($session->starts_at, false)),
            'seconds_until_join_open' => $this->secondsUntilJoinOpen($session, $window),
        ]);
    }

    /**
     * Every session of ONE course, for its page's sessions tab (US2 · FR-016).
     *
     * ⚠️ IT EXISTS BECAUSE `GET /class-sessions` ANSWERS A REAL STUDENT `403`,
     * AND THAT IS NOT A BUG THERE. `ClassSessionPolicy::viewAny()` asks for
     * `SESSIONS_VIEW`, and a student holds no spatie team id — they are a member
     * of no workspace, so `WorkspaceContext::id()` is null and every `can()`
     * below it is false. Measured, not assumed: the tab built against that route
     * caught the refusal into an empty list and told a student with a lesson
     * every week «لا حصص في هذه المادّة بعد». The endpoint that serves them is
     * one whose guard is the ENROLMENT, exactly as `nextForCourse()` above is,
     * and this is the only spelling of that question in the module.
     *
     * ⚠️ AND THE WINDOW IS BOUNDED FROM BOTH ENDS — «THE OLDEST FIFTY» DEFECT.
     * `/class-sessions` paginates at fifty ASCENDING, so an unbounded read of a
     * course in its second term returns its first fifty lessons and no upcoming
     * one at all, including the session the page's own header is counting down
     * to. Two bounded reads instead: the nearest ahead, and the most recent
     * behind. Bounded on `ends_at` so a lesson in progress counts as ahead,
     * which is where the join button is.
     *
     * ⚠️ AND THE CAP IS DECLARED RATHER THAN SILENT. A course with more than
     * fifty either side loses the far end; a term is well under it, and the
     * upgrade is paging this tab, not raising a number nobody can see.
     */
    public function forCourse(Request $request, Course $course, EnrollmentDirectory $enrollments): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $enrollments->hasActiveEnrollment($user, (int) $course->getKey())) {
            return $this->notEnrolled();
        }

        $base = fn (): Builder => ClassSession::query()
            ->where('course_id', $course->getKey())
            // The Resource asks every published session where its recording went,
            // and reads the viewer's own booking off the loaded relation.
            ->with(['course', 'bookings', 'recordingLesson']);

        $ahead = $base()->where('ends_at', '>=', now())->orderBy('starts_at')->limit(50)->get();
        $behind = $base()->where('ends_at', '<', now())->orderByDesc('starts_at')->limit(50)->get();

        return response()->json([
            'data' => ClassSessionResource::collection($behind->reverse()->concat($ahead)->values()),
        ]);
    }

    /**
     * `403`, and the wire value `LessonAccess::NOT_ENROLLED` carries — spelled
     * here rather than imported: LiveSessions asks Learning its questions
     * through {@see EnrollmentDirectory} and knows none of its classes.
     */
    private function notEnrolled(): JsonResponse
    {
        return response()->json([
            'message' => 'لا تملك تسجيلاً في هذا الكورس.',
            'code' => 'not_enrolled',
        ], 403);
    }

    /**
     * How long until the door opens — `0` for open now, `null` for never again.
     *
     * ⚠️ WITHOUT IT THE BUTTON NEVER APPEARS ON A PAGE LEFT OPEN. `join_open` is
     * a boolean answered once, at fetch: a student who opens the course twenty
     * minutes early watches the countdown reach «بدأت الآن» while the footer
     * still reads «يُفتح الدخول قبل الموعد» — for ever, until they reload. That
     * is FR-015's «حين» half unimplemented, and it is strictly worse than
     * guessing the window in the browser, which at least changes its mind as the
     * clock ticks.
     *
     * A second countdown rather than a poll, and seeded here for the same reason
     * the first one is: the browser may only tick a number down, never derive it
     * from `starts_at` and a clock that may be an hour out (SC-016).
     *
     * `null` is a distinct answer from a large number — a closed room and a
     * window already past both mean «this will not open», and a client counting
     * down to one of those would draw the button eventually.
     */
    private function secondsUntilJoinOpen(ClassSession $session, int $window): ?int
    {
        if ($session->room_closed_at !== null) {
            return null;
        }

        if (now()->greaterThan($session->ends_at->copy()->addMinutes($window))) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds(
            $session->starts_at->copy()->subMinutes($window),
            false,
        ));
    }
}
