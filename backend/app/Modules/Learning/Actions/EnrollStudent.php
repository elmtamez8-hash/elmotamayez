<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;

/**
 * Write the enrolment row — the ONE writer every door and every listener shares.
 *
 * ⚠️ AND THE «A TEACHER NEVER ENROLS» RULE IS DELIBERATELY **NOT** HERE, against
 * this repository's own habit of putting a business rule in the Action.
 *
 * This is the FULFILMENT path, not a door: `CreateEnrollmentFromOrder` and
 * `ActivateSubscription` both reach it AFTER a payment has been approved, and
 * both are queued. A refusal here would therefore not stop a purchase — it would
 * strand an order somebody already paid for in `failed_jobs`, with the money
 * taken and no seat written and nothing on any screen saying so. The window is
 * real rather than theoretical: a student buys a course today and applies to
 * teach next week, and their pending order is approved after the workspace
 * exists.
 *
 * The rule is enforced at the three places a purchase BEGINS — the free enrolment
 * door, the paid course order door, and `PurchaseSubscription`. It is also why
 * a teacher granting a course to a student, a seeder and the panel all still
 * reach this Action unhindered: none of them is a teacher buying for themselves.
 */
class EnrollStudent extends Action
{
    use LogsActivity;

    public function handle(Course $course, User $student, string $source = 'manual', ?int $orderId = null): Enrollment
    {
        $enrollment = Enrollment::firstOrCreate(
            [
                // The course owns the workspace; the current context may be null
                // when a Super Admin or a queued job performs the enrollment.
                'workspace_id' => $course->workspace_id,
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
            ],
            [
                'source' => $source,
                'order_id' => $orderId,
                'status' => 'active',
                'enrolled_at' => now(),
            ],
        );

        if ($enrollment->wasRecentlyCreated) {
            $this->logActivity('enrolled', $enrollment, [
                'course_title' => $course->title,
                'student_name' => $student->name,
                'source' => $source,
            ]);

            event(new EnrollmentCreated($enrollment));
        }

        return $enrollment;
    }
}
