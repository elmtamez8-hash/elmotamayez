<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

enum CaptionKind: string
{
    /** Same language as the audio, including non-speech sound. */
    case Captions = 'captions';

    /** A translation of the speech. */
    case Subtitles = 'subtitles';
}
