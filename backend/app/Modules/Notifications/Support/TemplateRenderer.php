<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Data\RenderedMessage;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Models\MessageTemplate;

/**
 * Turns a type plus its variables into the Arabic text a recipient reads.
 *
 * Every type and channel needs a template, in-app included (research R13). The
 * alternative — hardcoding in-app wording and templating the rest — leaves half
 * the product's sentences unreachable from the admin panel, which whoever wants
 * to fix a typo discovers the hard way.
 */
class TemplateRenderer
{
    /** @param array<string, mixed> $variables */
    public function render(NotificationType $type, NotificationChannel $channel, array $variables): RenderedMessage
    {
        $key = MessageTemplate::keyFor($type, $channel);

        $template = MessageTemplate::query()
            ->where('type', $type->value)
            ->where('channel', $channel->value)
            ->first();

        if ($template === null) {
            throw PermanentDeliveryException::templateMissing($key);
        }

        // Refused rather than sent (FR-037). WhatsApp and SMS providers reject an
        // unapproved template anyway; refusing here makes the reason readable in
        // the delivery log instead of arriving as a provider error code.
        if (! $template->isSendable()) {
            throw PermanentDeliveryException::templateNotApproved($key);
        }

        $missing = $this->missingVariables($template, $variables);

        if ($missing !== []) {
            throw PermanentDeliveryException::missingVariables($key, $missing);
        }

        return new RenderedMessage(
            title: $this->interpolate($template->title, $variables),
            body: $this->interpolate($template->body, $variables),
            templateId: (int) $template->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return list<string>
     */
    private function missingVariables(MessageTemplate $template, array $variables): array
    {
        $required = $template->variables ?? [];

        return array_values(array_filter(
            $required,
            // Present-but-null counts as missing: a body reading "درجتك: " with
            // nothing after it is worse than no message at all.
            static fn (string $name): bool => ! isset($variables[$name]) || $variables[$name] === '',
        ));
    }

    /** @param array<string, mixed> $variables */
    private function interpolate(string $text, array $variables): string
    {
        foreach ($variables as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $text = str_replace(
                    ['{{ '.$name.' }}', '{{'.$name.'}}'],
                    (string) $value,
                    $text,
                );
            }
        }

        return $text;
    }
}
