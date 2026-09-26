<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/*
| The framework's own mails are the first thing a new account reads, and the
| verification mail shipped half English: «Verify your email address», «Please
| click the button below…», «If you did not create an account…» and «All rights
| reserved.» around an Arabic greeting (end-to-end run, 2026-09-26). `lang/ar.json`
| carried the reset keys and not the verification ones.
|
| ⚠️ THE ASSERTIONS READ DECODED TEXT. Blade escapes every non-ASCII character to
| an entity, so a raw `toContain('تأكيد')` against the HTML is vacuously false and
| a `not->toContain('Verify')` alone is the only half that would bite
| (docs/gotchas/testing.md). And the English check is not a list of phrases —
| a list only knows the strings somebody already saw — but «no Latin word left
| once the URLs are removed».
*/

/** The mail as a reader sees it: tags gone, entities decoded, links removed. */
function readableMailText(MailMessage $mail): string
{
    $html = html_entity_decode((string) $mail->render(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = preg_replace('/<style\b[^>]*>.*?<\/style>/si', ' ', $html) ?? '';
    $text = strip_tags($body);

    return preg_replace('~https?://\S+~', ' ', $text) ?? '';
}

function renderedMailHtml(MailMessage $mail): string
{
    return html_entity_decode((string) $mail->render(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

beforeEach(function (): void {
    PlatformSettings::set('platform.name', 'المتميز');
});

it('sends the verification mail entirely in Arabic, right to left, under the platform name', function (): void {
    $user = User::factory()->unverified()->create();

    $mail = (new VerifyEmail)->toMail($user);

    expect($mail->subject)->toBe('تأكيد بريدك الإلكتروني')
        ->and($mail->actionText)->toBe('تأكيد البريد الإلكتروني');

    $text = readableMailText($mail);

    expect($text)
        ->toContain('اضغط الزرّ أدناه لتأكيد بريدك الإلكتروني.')
        ->toContain('جميع الحقوق محفوظة.')
        ->toContain('المتميز')
        ->not->toMatch('/[A-Za-z]{2,}/');

    expect(renderedMailHtml($mail))->toContain('dir="rtl"')->not->toContain('text-align: left');
});

it('sends the password-reset mail entirely in Arabic, right to left, under the platform name', function (): void {
    $user = User::factory()->create();

    $mail = (new ResetPassword('a-token'))->toMail($user);

    expect($mail->subject)->toBe('إعادة ضبط كلمة المرور');

    $text = readableMailText($mail);

    expect($text)
        ->toContain('إن لم يعمل زرّ «إعادة ضبط كلمة المرور»')
        ->toContain('جميع الحقوق محفوظة.')
        ->toContain('المتميز')
        ->not->toMatch('/[A-Za-z]{2,}/');

    expect(renderedMailHtml($mail))->toContain('dir="rtl"')->not->toContain('text-align: left');
});
