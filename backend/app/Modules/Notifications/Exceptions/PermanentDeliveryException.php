<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Exceptions;

use RuntimeException;

/**
 * A failure that retrying cannot fix.
 *
 * The only thing this type changes is the retry decision (FR-009): it is logged
 * as failed immediately, where any other exception is retried up to five times
 * with escalating backoff. Retrying a wrong phone number five times spends the
 * provider's rate budget to learn what the first attempt already said.
 */
final class PermanentDeliveryException extends RuntimeException
{
    /**
     * ⚠️ `$reason` IS TEXT WE WROTE, NEVER TEXT A PROVIDER SENT. The message of
     * every exception here is stored verbatim in `notification_deliveries.
     * failure_reason`, and a provider's own error text can quote the number or
     * the message it refused. A provider's refusal goes through
     * {@see self::providerRefused()}, which carries only its status and code.
     */
    public static function invalidRecipient(string $reason, int $providerCode = 0): self
    {
        return new self($reason, $providerCode);
    }

    /**
     * The provider refused the message and said retrying will not help.
     *
     * Built from the HTTP status and the provider's NUMERIC code alone — both
     * enough for an operator to look the refusal up, neither able to carry a
     * phone number or a line of the message. `$providerCode` also rides in the
     * exception CODE, as it always has, so a caller can log it.
     */
    public static function providerRefused(int $status, int $providerCode = 0): self
    {
        return new self("رفض مزوّد القناة الرسالة (HTTP {$status}، رمز المزوّد {$providerCode}).", $providerCode);
    }

    public static function templateMissing(string $key): self
    {
        return new self("لا يوجد قالب للمفتاح {$key}.");
    }

    public static function templateNotApproved(string $key): self
    {
        return new self("القالب {$key} غير معتمد لدى مزوّد القناة.");
    }

    /** @param list<string> $missing */
    public static function missingVariables(string $key, array $missing): self
    {
        return new self("القالب {$key} ينقصه متغيّرات: ".implode('، ', $missing).'.');
    }

    public static function blockedByRecipient(): self
    {
        return new self('المستلم أوقف استقبال الرسائل من المنصة.');
    }
}
