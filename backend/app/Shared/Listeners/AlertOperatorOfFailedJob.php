<?php

declare(strict_types=1);

namespace App\Shared\Listeners;

use App\Shared\Mail\JobFailedAlert;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mails the operator when a queued job fails for good.
 *
 * Horizon's own notifications cover a queue that WAITS too long; a job that
 * fails is only a row in `failed_jobs`, and nobody opens /horizon to look for
 * rows. The payment sweep, the recording pipeline and the certificate issue all
 * run here, so a silent failure is money or a paid lesson that never arrives.
 *
 * ⚠️ ONE MAIL PER JOB CLASS PER HOUR. A failing dependency fails every job that
 * touches it, hundreds a minute; a mail each would bury the one that matters and
 * burn the SMTP relay's daily quota — the same relay password resets use. The
 * `Cache::add()` is atomic, so two workers failing the same class at once still
 * send one.
 *
 * ⚠️ AND IT NEVER THROWS. It runs inside the worker's failure path: an SMTP or
 * Redis outage here must not turn one failed job into a crashed worker.
 */
final class AlertOperatorOfFailedJob
{
    public const THROTTLE_SECONDS = 3600;

    public function handle(JobFailed $event): void
    {
        $recipient = config('horizon.notification_email');

        // Unset means "not configured", the same reading Horizon's own routing gives it.
        if (! is_string($recipient) || $recipient === '') {
            return;
        }

        $jobName = $event->job->resolveName();

        try {
            if (! Cache::add('ops:job-failed-alert:'.sha1($jobName), true, self::THROTTLE_SECONDS)) {
                return;
            }

            Mail::to($recipient)->send(new JobFailedAlert(
                jobName: $jobName,
                queue: $event->job->getQueue(),
                connection: $event->connectionName,
                exceptionClass: $event->exception::class,
                jobUuid: $event->job->uuid(),
            ));
        } catch (Throwable $e) {
            // The class only, never the message — see JobFailedAlert.
            Log::warning('ops.job_failed_alert.undelivered', [
                'job' => $jobName,
                'exception' => $e::class,
            ]);
        }
    }
}
