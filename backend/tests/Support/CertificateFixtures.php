<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Certificates\Actions\IssueCertificate;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Support\Str;

/*
| A class rather than a function declared in one of the test files: Pest only
| loads a file when it collects it, so a helper written inside `VerifyPayloadTest`
| is undefined whenever a sibling file is run on its own — and "undefined
| function" looks exactly like a broken feature while hiding whether the real
| assertion would have passed. That mattered here: proving the scope-bypass test
| actually bites means running that ONE file with the bypass removed.
*/
final class CertificateFixtures
{
    /** Issues a certificate whose teacher name is known to the caller. */
    public static function issue(int $workspaceId, int $studentId, ?int $teacherId = null): Certificate
    {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspaceId,
            'is_sequential' => false,
            'created_by' => $teacherId,
        ]);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspaceId,
            'uuid' => Str::uuid(),
            'course_id' => $course->id,
            'student_user_id' => $studentId,
            'status' => 'completed',
            'enrolled_at' => now(),
            'completed_at' => now(),
        ]);

        return app(IssueCertificate::class)->handle($enrollment, 'course_completed');
    }
}
