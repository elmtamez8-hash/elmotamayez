<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The operator's mail that a queued job has failed.
 *
 * ⚠️ NOT `ShouldQueue`, and it must never become it: this mail reports that the
 * queue is failing, so a copy waiting on that same queue is the alert nobody gets.
 *
 * ⚠️ AND IT CARRIES NO EXCEPTION MESSAGE. A `QueryException` interpolates its
 * bindings into the message — an address, a phone number, a name — and this mail
 * leaves through a third-party SMTP relay (docs/gotchas/http-and-security.md).
 * The class of the exception says what kind of failure it is; the message and the
 * trace stay in `failed_jobs`, in our own database, one click away on /horizon.
 */
final class JobFailedAlert extends Mailable
{
    public function __construct(
        public readonly string $jobName,
        public readonly string $queue,
        public readonly string $connection,
        public readonly string $exceptionClass,
        public readonly ?string $jobUuid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'مهمّةٌ فشلت في الطابور: '.class_basename($this->jobName));
    }

    public function content(): Content
    {
        $horizon = rtrim((string) config('app.url'), '/').'/horizon/failed';

        $rows = [
            'المهمّة' => $this->jobName,
            'الطابور' => $this->connection.' / '.$this->queue,
            'نوع الخطأ' => $this->exceptionClass,
            'المعرّف' => $this->jobUuid ?? '—',
        ];

        $html = '<div dir="rtl"><p>فشلت مهمّةٌ في الطابور. التفاصيلُ الكاملةُ ونصُّ الخطأِ في Horizon.</p><ul>';
        foreach ($rows as $label => $value) {
            $html .= '<li>'.e($label).': <code>'.e($value).'</code></li>';
        }
        $html .= '</ul><p><a href="'.e($horizon).'">'.e($horizon).'</a></p>'
            .'<p>لا تصلُ رسالةٌ ثانيةٌ عن المهمّةِ نفسِها قبلَ ساعة، مهما تكرّرَ فشلُها.</p></div>';

        return new Content(htmlString: $html);
    }
}
