<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/**
 * The provider refused in a way that a second attempt cannot fix.
 *
 * Exists so "try again in fifteen minutes" and "this will never work" are not the
 * same answer. The retry loop treats every throw as "not yet" and burns the
 * attempt budget — right for a rate limit or a source URL that expired mid-fetch,
 * wrong for a malformed request or a wrong key, where five more attempts over an
 * hour tell the teacher nothing they could not have been told at once.
 */
class PermanentIngestFailure extends RuntimeException {}
