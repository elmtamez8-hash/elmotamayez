<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Exceptions;

use RuntimeException;

/**
 * A notification claiming to come from the broadcast provider whose signature
 * does not hold — answered 401, and nothing in it is read.
 */
final class InvalidBroadcastSignature extends RuntimeException {}
