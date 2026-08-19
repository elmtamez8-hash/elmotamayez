<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Contracts\SendsVerificationCodes;
use App\Modules\Notifications\Data\NotificationEnvelope;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\PhoneNumber;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The first channel that leaves the platform (spec 020).
 *
 * ⚠️ IT NAMES NO VENDOR, AND THAT IS NOT DECORATION. The base URL and the auth
 * header are both configuration, because the provider we send through fronts
 * Meta's Cloud API with a request body that is identical byte for byte — so
 * moving between a reseller and Meta direct is two env values, and a branch on a
 * provider name here would have been two branches by the second one.
 *
 * ⚠️ EVERY MESSAGE IS A TEMPLATE, AND THERE IS NO OTHER KIND. Free-form text is
 * permitted only inside the 24-hour window a user opens BY REPLYING, and we
 * receive nothing — so the window never opens. What travels is a template NAME
 * and an ORDERED list of parameters; the Arabic sentence lives at Meta.
 *
 * ⚠️ WHICH MEANS `message_templates.body_ar` IS DOCUMENTATION HERE, NOT TEXT.
 * Editing it from the admin panel changes what the notification bell shows and
 * changes nothing at all on a phone. The row still matters for two things a
 * message cannot be sent without: the ORDER of `variables`, which is the meaning
 * of every parameter, and `provider_approval_status`, which is how the system
 * learns the outcome of a human process at Meta.
 */
class WhatsAppChannel implements NotificationChannelInterface, SendsVerificationCodes
{
    /** The template key for the one message that goes to an unproven number. */
    public const VERIFICATION_TEMPLATE_TYPE = 'contact_verification';

    public function channel(): NotificationChannel
    {
        return NotificationChannel::WhatsApp;
    }

    /**
     * Configured and switched on.
     *
     * An unconfigured deployment answers false and every delivery is recorded
     * `skipped`, never `failed` — being unconfigured is a state of the
     * deployment, not an incident, and a wall of red rows would train whoever
     * reads that log to stop reading it.
     */
    public function isEnabled(): bool
    {
        return (bool) config('notifications.whatsapp.enabled')
            && is_string(config('notifications.whatsapp.api_key'))
            && config('notifications.whatsapp.api_key') !== ''
            && is_string(config('notifications.whatsapp.base_url'))
            && config('notifications.whatsapp.base_url') !== '';
    }

    public function canReach(NotificationEnvelope $envelope): bool
    {
        return $this->verifiedNumber($envelope) !== null;
    }

    public function send(NotificationEnvelope $envelope): void
    {
        $to = $this->verifiedNumber($envelope);

        if ($to === null) {
            // Reached only if canReach() and send() disagree, which would mean
            // the row was deleted between the two. Refused rather than guessed.
            throw PermanentDeliveryException::invalidRecipient('لا يوجد رقم واتساب مُتحقَّق منه لهذا الحساب.');
        }

        $template = $this->template($envelope->type->value);

        $this->post($to, $template, $this->orderedParameters($template, $envelope->payload));
    }

    public function sendVerificationCode(string $toE164, string $code): void
    {
        $this->post($toE164, $this->template(self::VERIFICATION_TEMPLATE_TYPE), [$code]);
    }

    private function verifiedNumber(NotificationEnvelope $envelope): ?string
    {
        return ContactVerification::verifiedValueFor($envelope->recipient, $this->channel());
    }

    /**
     * The row that says which approved template to name, and in what order its
     * parameters go.
     *
     * Read by key rather than through TemplateRenderer, which is typed to
     * NotificationType and renders TEXT. There is no text to render here — see
     * the class docblock — and the verification message is not a NotificationType
     * at all, so one lookup serves both callers instead of two shapes serving one
     * need.
     */
    private function template(string $type): MessageTemplate
    {
        $key = $type.'.'.$this->channel()->value;

        $template = MessageTemplate::query()
            ->where('type', $type)
            ->where('channel', $this->channel()->value)
            ->first();

        if ($template === null) {
            throw PermanentDeliveryException::templateMissing($key);
        }

        // Refused here, with the key in the message, rather than sent and
        // refused by the provider as an error code nobody can map back to a row.
        if (! $template->isSendable()) {
            throw PermanentDeliveryException::templateNotApproved($key);
        }

        return $template;
    }

    /**
     * The template's declared variables, in their declared order, read out of the
     * notification's payload.
     *
     * ⚠️ ORDER IS THE WHOLE MEANING. The provider's template carries numbered
     * placeholders, so a list built by iterating the payload — whose key order is
     * whatever the listener happened to write — would put a student's name where
     * the date belongs, in a message to their parent, with no error anywhere.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function orderedParameters(MessageTemplate $template, array $payload): array
    {
        $names = $template->variables ?? [];
        $missing = [];
        $values = [];

        foreach ($names as $name) {
            $value = $payload[$name] ?? null;

            if ($value === null || $value === '' || ! is_scalar($value)) {
                $missing[] = $name;

                continue;
            }

            $values[] = (string) $value;
        }

        if ($missing !== []) {
            throw PermanentDeliveryException::missingVariables($template->key, $missing);
        }

        return $values;
    }

    /**
     * @param  list<string>  $parameters
     */
    private function post(string $to, MessageTemplate $template, array $parameters): void
    {
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            // The provider wants digits without the leading plus.
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template->type,
                'language' => ['code' => (string) config('notifications.whatsapp.language')],
                'components' => $parameters === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(
                        static fn (string $value): array => ['type' => 'text', 'text' => $value],
                        $parameters,
                    ),
                ]],
            ],
        ];

        $response = Http::withHeaders([
            (string) config('notifications.whatsapp.auth_header') => (string) config('notifications.whatsapp.api_key'),
        ])
            ->timeout((int) config('notifications.whatsapp.timeout_seconds', 15))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) config('notifications.whatsapp.base_url'), '/').'/messages', $body);

        if ($response->successful()) {
            return;
        }

        $this->fail($response, $to, $template->key);
    }

    /**
     * Permanent or transient — decided by the PROVIDER'S OWN ANSWER.
     *
     * ⚠️ NOT BY A LIST OF ERROR CODES COPIED INTO THIS FILE. The error body
     * carries `is_transient` explicitly, and a hand-copied code list ages at the
     * provider's next release — after which it misreads a real outage as
     * permanent and drops the message, or a bad number as transient and burns
     * five attempts and the rate budget to learn what the first reply said.
     *
     * So: a 4xx that is not a 429 and does not claim to be transient is
     * permanent. Everything else — 429, every 5xx, a timeout, a refused
     * connection — is retried by the job with its escalating backoff.
     */
    private function fail(Response $response, string $to, string $templateKey): never
    {
        $error = $response->json('error');
        $isTransient = is_array($error) && ($error['is_transient'] ?? false) === true;
        $status = $response->status();
        $reason = is_array($error) && is_string($error['message'] ?? null)
            ? $error['message']
            : 'HTTP '.$status;

        // ⚠️ The number is MASKED. A delivery log is the one place nobody guards
        // and everybody forwards to a monitoring vendor (FR-022).
        Log::warning('[notifications] whatsapp send failed', [
            'status' => $status,
            'template' => $templateKey,
            'to' => PhoneNumber::mask($to),
            'transient' => $isTransient,
            'reason' => $reason,
        ]);

        if ($status >= 400 && $status < 500 && $status !== 429 && ! $isTransient) {
            throw PermanentDeliveryException::invalidRecipient($reason);
        }

        throw new RuntimeException($reason);
    }
}
