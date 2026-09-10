<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Actions;

use App\Modules\Certificates\Events\CertificateIssued;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues a certificate for a completed enrollment or passed exam.
 *
 * Idempotent: if a certificate already exists for the same (workspace_id, enrollment_id, course_id),
 * the existing one is returned and no event is fired.
 */
class IssueCertificate extends Action
{
    use LogsActivity;

    public function handle(Enrollment $enrollment, string $reason, ?int $examAttemptId = null): Certificate
    {
        return DB::transaction(function () use ($enrollment, $reason, $examAttemptId): Certificate {
            // Idempotency check inside the transaction (with the unique constraint as backstop).
            $existing = Certificate::where('workspace_id', $enrollment->workspace_id)
                ->where('enrollment_id', $enrollment->getKey())
                ->where('course_id', $enrollment->course_id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $certificate = Certificate::create([
                'workspace_id' => $enrollment->workspace_id,
                'certificate_number' => $this->generateNumber(),
                'verification_code' => Str::random(40),
                'enrollment_id' => $enrollment->getKey(),
                'course_id' => $enrollment->course_id,
                'student_user_id' => $enrollment->student_user_id,
                /*
                | ⚠️ FROZEN AT ISSUE, AND THE PUBLIC VERIFY READS THIS AND NOT
                | `users`. A live join makes an erasure unanswerable: null the
                | student and the certificate verifies as nobody, delete the row and
                | a credential the student earned is destroyed, anonymise the joined
                | name and a public statement of fact is silently rewritten. The
                | certificate keeps saying what it said on the day it was earned.
                */
                'student_display_name' => $enrollment->student->name,
                /*
                | ⚠️ FROZEN FOR THE SAME REASON THE STUDENT'S NAME IS, and the
                | reason only became visible when the certificate started being
                | DRAWN: the teacher's name and the subject are printed on the
                | artwork. A live join through `courses.created_by` would rewrite
                | a public statement of fact the day a teacher renames themselves,
                | leaves the platform, or the course is re-filed under another
                | subject — and would answer with nobody at all once the account
                | is erased. `created_by` is nullable (a course outlives its
                | author), so both columns are nullable and the page falls back to
                | the course title rather than printing an empty line.
                */
                'teacher_display_name' => $enrollment->course->creator?->name,
                // `->` and not `?->` on the left of `??`: `??` already uses isset
                // semantics, so a null course subject yields the title rather
                // than an error — and PHPStan rejects the redundant nullsafe.
                'subject_display_name' => $enrollment->course->subject->name
                    ?? $enrollment->course->title,
                'exam_attempt_id' => $examAttemptId,
                'issue_reason' => $reason,
                'issued_at' => now(),
                'metadata' => [
                    'course_title' => $enrollment->course->title,
                    'student_name' => $enrollment->student->name,
                ],
            ]);

            event(new CertificateIssued($certificate));

            $this->logActivity('issued', $certificate, [
                'certificate_number' => $certificate->certificate_number,
                'reason' => $reason,
            ]);

            return $certificate;
        });
    }

    private function generateNumber(): string
    {
        return 'CERT-'.now()->format('Y').'-'.strtoupper(Str::random(8));
    }
}
