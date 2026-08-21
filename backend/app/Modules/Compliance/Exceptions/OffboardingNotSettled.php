<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Exceptions;

use DomainException;

/**
 * An exit was pressed while money is still outstanding, or the notice still runs.
 *
 * ⚠️ A `DomainException`, UNLIKE {@see LegalHoldInForce}, and the difference is
 * where each is thrown. A hold interrupts a queued walk, where the honest outcome
 * is a failed job an officer can see. This one is raised inside a REQUEST, by an
 * officer who just pressed a button — `bootstrap/app.php` renders it as a 422
 * carrying this message, which is the sentence they need: what is unsettled, or
 * which date has not arrived.
 */
final class OffboardingNotSettled extends DomainException
{
    public function __construct(string $message = 'لا يمكن إتمام الخروج قبل حسم المستحقّات.')
    {
        parent::__construct($message);
    }
}
