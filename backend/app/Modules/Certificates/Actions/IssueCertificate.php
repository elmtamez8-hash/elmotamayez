<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Actions;

use App\Modules\Certificates\Events\CertificateIssued;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Certificates\Models\CertificateTemplate;
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

            $template = $this->resolveTemplate($enrollment->workspace_id);

            $certificate = Certificate::create([
                'workspace_id' => $enrollment->workspace_id,
                'certificate_number' => $this->generateNumber(),
                'verification_code' => Str::random(40),
                'enrollment_id' => $enrollment->getKey(),
                'course_id' => $enrollment->course_id,
                'student_user_id' => $enrollment->student_user_id,
                'exam_attempt_id' => $examAttemptId,
                'issue_reason' => $reason,
                'issued_at' => now(),
                'template_id' => $template?->getKey(),
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

    private function resolveTemplate(int $workspaceId): ?CertificateTemplate
    {
        return CertificateTemplate::where('workspace_id', $workspaceId)->first();
    }

    private function generateNumber(): string
    {
        return 'CERT-'.now()->format('Y').'-'.strtoupper(Str::random(8));
    }
}
