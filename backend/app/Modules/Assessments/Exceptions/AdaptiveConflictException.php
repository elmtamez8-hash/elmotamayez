<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Exceptions;

use DomainException;

/**
 * Somebody — usually the same person's second tab — got there first.
 *
 * ⚠️ A TYPE, NOT A MESSAGE. The adaptive routes answer `409` for a duplicate
 * start and a duplicate answer and `422` for every other refusal, and a
 * controller cannot tell those apart by reading a sentence. It extends
 * `DomainException` so a caller that only knows about the general case still
 * refuses cleanly; the controller catches this one ABOVE that arm — the same
 * ordering `BroadcastProviderUnavailable` needs above `RuntimeException`, and
 * for the same reason: caught in the wrong order the specific answer is
 * swallowed by the general one.
 */
class AdaptiveConflictException extends DomainException {}
