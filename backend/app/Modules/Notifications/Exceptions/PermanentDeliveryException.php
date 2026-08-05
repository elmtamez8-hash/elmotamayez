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
    public static function invalidRecipient(string $reason): self
    {
        return new self($reason);
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
