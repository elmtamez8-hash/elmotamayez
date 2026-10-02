<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use RuntimeException;

/** The file is not a PDF we can read — broken, encrypted, or something else. */
final class PdfUnreadable extends RuntimeException {}
