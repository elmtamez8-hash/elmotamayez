<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Channels\WhatsAppChannel;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
| The first channel that leaves the platform (spec 020).
|
| ⚠️ Http::fake() APPENDS stub sets and the FIRST matching pattern wins, so a
| test that fakes one answer and later re-fakes another still gets the first —
| which reads as a broken feature and invites "fixing" the assertion. Every case
| below therefore installs its own fake ONCE, the way BunnyIngestTest had to.
*/

// ---------------------------------------------------------------------------
// SC-002 — an unconfigured deployment is a state, not an incident.
// ---------------------------------------------------------------------------

it('is disabled with no credentials, and asks the network for nothing', function (): void {
    Http::preventStrayRequests();
    configureWhatsApp(enabled: false);

    // Not "throws", not "fails" — off. DispatchNotification records every
    // delivery `skipped` on the back of exactly this answer, so an operator who
    // has not signed up yet reads an empty column instead of a wall of red.
    expect(app(WhatsAppChannel::class)->isEnabled())->toBeFalse();
});

// ---------------------------------------------------------------------------
// SC-003 — unreachable is skipped, never failed.
// ---------------------------------------------------------------------------

it('cannot reach an account with no verified number, and never reads users.phone', function (): void {
    configureWhatsApp();

    $user = User::factory()->create(['phone' => '+97433123456']);

    // The column is populated and it is still false. That column is a free
    // string nobody confirmed; a typo in it is a message about a child sent to
    // a stranger.
    expect(app(WhatsAppChannel::class)->canReach(envelopeFor($user)))->toBeFalse();
});

it('reaches an account whose number was proven', function (): void {
    configureWhatsApp();

    expect(app(WhatsAppChannel::class)->canReach(envelopeFor(withVerifiedWhatsApp())))->toBeTrue();
});

// ---------------------------------------------------------------------------
// The happy path — a template NAME and ORDERED parameters, never Arabic text.
// ---------------------------------------------------------------------------

it('sends the template name and its parameters in their declared order', function (): void {
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);
    Http::fake(['provider.test/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

    app(WhatsAppChannel::class)->send(envelopeFor(withVerifiedWhatsApp()));

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        $parameters = array_column($body['template']['components'][0]['parameters'], 'text');

        return $request->hasHeader('X-Test-Key', 'test-key')
            && $body['to'] === '97433123456'
            && $body['type'] === 'template'
            && $body['template']['name'] === 'session_report'
            && $body['template']['language']['code'] === 'ar'
            // ⚠️ ORDER IS THE MEANING. The approved template numbers its
            // placeholders, so a list built by walking the payload — whose key
            // order is whatever a listener happened to write — puts the student's
            // name where the duration belongs, in a message to their parent, with
            // no error anywhere.
            && $parameters === ['حصّة الجبر', 'سلمى', 'حاضرة', '45', 'أداء جيّد'];
    });
});

it('sends no Arabic body text, because the wording lives at the provider', function (): void {
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);
    Http::fake(['provider.test/*' => Http::response([], 200)]);

    app(WhatsAppChannel::class)->send(envelopeFor(withVerifiedWhatsApp()));

    Http::assertSent(fn ($request): bool => ! str_contains(
        json_encode($request->data(), JSON_UNESCAPED_UNICODE) ?: '',
        'حالة سلمى في حصة',
    ));
});

// ---------------------------------------------------------------------------
// SC-004 — a template awaiting approval is refused HERE, with its key.
// ---------------------------------------------------------------------------

it('refuses a template the provider has not approved, naming it', function (): void {
    Http::preventStrayRequests();
    configureWhatsApp();

    // Seeded `pending` on purpose: claiming an approval that has not happened
    // turns the first send into a provider error code nobody can map to a row.
    app(WhatsAppChannel::class)->send(envelopeFor(withVerifiedWhatsApp()));
})->throws(PermanentDeliveryException::class, 'session_report.whatsapp');

it('refuses a type with no whatsapp template at all', function (): void {
    Http::preventStrayRequests();
    configureWhatsApp();

    MessageTemplate::query()
        ->where('type', NotificationType::SessionReport->value)
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->delete();

    app(WhatsAppChannel::class)->send(envelopeFor(withVerifiedWhatsApp()));
})->throws(PermanentDeliveryException::class);

// ---------------------------------------------------------------------------
// SC-007 — permanent vs transient, decided by the provider's own answer.
// ---------------------------------------------------------------------------

it('treats a 4xx with no transient flag as permanent, so the job never retries it', function (): void {
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);
    Http::fake(['provider.test/*' => Http::response([
        'error' => ['message' => 'Invalid recipient', 'code' => 131026],
    ], 400)]);

    app(WhatsAppChannel::class)->send(envelopeFor(withVerifiedWhatsApp()));
})->throws(PermanentDeliveryException::class);

it('treats a 4xx that declares itself transient as transient', function (): void {
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);
    // ⚠️ Read from the ANSWER, not from a code list copied into our tree: a list
    // ages at the provider's next release and then silently drops real messages.
    Http::fake(['provider.test/*' => Http::response([
        'error' => ['message' => 'Application request limit reached', 'code' => 4, 'is_transient' => true],
    ], 400)]);

    try {
        app(WhatsAppChannel::class)->send(envelopeFor(withVerifiedWhatsApp()));
        $this->fail('expected a throw');
    } catch (PermanentDeliveryException) {
        $this->fail('a self-declared transient error must be retried, not failed permanently');
    } catch (RuntimeException) {
        expect(true)->toBeTrue();
    }
});

it('treats 429 and 5xx as transient', function (int $status): void {
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);
    Http::fake(['provider.test/*' => Http::response(['error' => ['message' => 'later']], $status)]);

    try {
        app(WhatsAppChannel::class)->send(envelopeFor(withVerifiedWhatsApp()));
        $this->fail('expected a throw');
    } catch (PermanentDeliveryException) {
        $this->fail("status {$status} must be retried, not marked permanently failed");
    } catch (RuntimeException) {
        expect(true)->toBeTrue();
    }
})->with([429, 500, 503]);

// ---------------------------------------------------------------------------
// SC-010 — no phone number in any log line.
// ---------------------------------------------------------------------------

it('never writes a full phone number to the log', function (): void {
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);
    Http::fake(['provider.test/*' => Http::response(['error' => ['message' => 'nope']], 400)]);

    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $lines[] = json_encode($message->context, JSON_UNESCAPED_UNICODE);
    });

    try {
        app(WhatsAppChannel::class)->send(envelopeFor(withVerifiedWhatsApp('+97433123456')));
    } catch (PermanentDeliveryException) {
        // The refusal is the point of the case; the log line is what is measured.
    }

    // A delivery log is the one place nobody guards and everybody forwards to a
    // monitoring vendor.
    expect($lines)->not->toBeEmpty()
        ->and(implode("\n", $lines))->not->toContain('97433123456')
        ->and(implode("\n", $lines))->toContain('3456');
});
