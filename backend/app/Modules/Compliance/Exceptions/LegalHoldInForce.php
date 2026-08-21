<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Exceptions;

use RuntimeException;

/**
 * An erasure met a hold — at the door, or halfway through the walk.
 *
 * ⚠️ A `RuntimeException`, NOT A `DomainException`, AND THAT IS DELIBERATE.
 * `bootstrap/app.php` renders every `DomainException` reaching an API route as a
 * 422 carrying its message; this one is thrown inside a queued job, frequently
 * mid-walk, where the correct outcome is a FAILED JOB an officer can see rather
 * than a tidy answer delivered to nobody. A hold discovered at batch nine is not a
 * validation result — it is the walk stopping, with part of the person already
 * erased and the rest deliberately not.
 */
final class LegalHoldInForce extends RuntimeException {}
