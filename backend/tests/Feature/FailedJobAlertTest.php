<?php

declare(strict_types=1);

use App\Shared\Mail\JobFailedAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Mail;

/*
| A job that fails for good mails the operator — once per job class per hour.
|
| Each test fires the real `JobFailed` event through the dispatcher rather than
| calling the listener, so the wiring in HorizonServiceProvider is what is proved.
*/

function failedJobEvent(string $class, string $message = 'boom'): JobFailed
{
    $payload = json_encode(['displayName' => $class, 'uuid' => 'uuid-'.$class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]);

    return new JobFailed('redis', new SyncJob(app(), (string) $payload, 'redis', 'default'), new RuntimeException($message));
}

beforeEach(function (): void {
    Mail::fake();
    config(['horizon.notification_email' => 'ops@example.test']);
});

it('mails the operator once per job class per hour, however many copies fail', function (): void {
    event(failedJobEvent('App\\Jobs\\FirstJob'));
    event(failedJobEvent('App\\Jobs\\FirstJob'));
    event(failedJobEvent('App\\Jobs\\FirstJob'));
    event(failedJobEvent('App\\Jobs\\SecondJob'));

    Mail::assertSent(JobFailedAlert::class, 2);
    Mail::assertSent(JobFailedAlert::class, fn (JobFailedAlert $mail): bool => $mail->hasTo('ops@example.test')
        && $mail->jobName === 'App\\Jobs\\FirstJob'
        && $mail->exceptionClass === RuntimeException::class);

    // The hour passes: the same class may alert again.
    $this->travel(61)->minutes();
    event(failedJobEvent('App\\Jobs\\FirstJob'));

    Mail::assertSent(JobFailedAlert::class, 3);
});

it('never puts the exception message in the mail', function (): void {
    // A QueryException carries its bindings in its message, and this mail
    // leaves through a third-party relay.
    event(failedJobEvent('App\\Jobs\\LeakyJob', 'select * from users where email = leak-needle@example.test'));

    Mail::assertSent(JobFailedAlert::class, function (JobFailedAlert $mail): bool {
        $html = $mail->render();

        expect($html)->not->toContain('leak-needle')
            ->and($html)->toContain('LeakyJob');

        return true;
    });
});

it('sends nothing when no operator address is configured', function (): void {
    config(['horizon.notification_email' => null]);

    event(failedJobEvent('App\\Jobs\\FirstJob'));

    Mail::assertNothingSent();
});

it('is not queued, because the queue is what is failing', function (): void {
    expect(is_subclass_of(JobFailedAlert::class, ShouldQueue::class))->toBeFalse();
});
