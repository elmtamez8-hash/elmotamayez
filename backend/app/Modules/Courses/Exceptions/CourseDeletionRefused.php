<?php

declare(strict_types=1);

namespace App\Modules\Courses\Exceptions;

use DomainException;

/**
 * A course somebody has enrolled in or paid for cannot be deleted.
 *
 * Thrown from `Course::booted()`'s `deleting` hook, which is the one place every
 * door reaches — the API's `destroy`, the panel's delete button and its bulk
 * delete all end in `$course->delete()`. The message is the sentence the teacher
 * reads, in Arabic, because the controller returns it as the 422 body.
 */
class CourseDeletionRefused extends DomainException {}
