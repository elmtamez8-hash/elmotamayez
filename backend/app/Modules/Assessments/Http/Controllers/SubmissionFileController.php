<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The file a student handed in (FR-048 · FR-048أ).
 *
 * ⚠️ THE LINK IS SIGNED FOR A READER, NOT FOR A PATH. A signature over the url
 * alone is a bearer token in a shape people paste: dropped into a group chat it
 * opens for everyone in the room, which is precisely the "shareable link" FR-048
 * forbids. The reader's own uuid is inside the signature and compared against
 * the authenticated user, so a pasted link is worthless to anybody else.
 *
 * ⚠️ AND THE POLICY RUNS AGAIN AT OPEN. A signature proves who asked for the
 * link; it cannot know whether they may still read. Without the re-check an
 * assistant whose grading permission was withdrawn keeps opening coursework for
 * the rest of the window — a five-minute hole, every time, with no way to close
 * it early.
 *
 * ⚠️ AND `auth:sanctum` SITS BESIDE `signed`. Either alone is not enough: the
 * signature without a session has no reader to compare against, and the session
 * without the signature makes the url guessable from a uuid.
 */
class SubmissionFileController extends Controller
{
    /** Five minutes, declared once, and the only place it is decided. */
    public const TTL_MINUTES = 5;

    public static function linkFor(Submission $submission, string $readerUuid): string
    {
        return URL::temporarySignedRoute(
            'submissions.file',
            now()->addMinutes(self::TTL_MINUTES),
            [
                'submission' => $submission->uuid,
                'reader' => $readerUuid,
            ],
        );
    }

    public function __invoke(Request $request, Submission $submission): StreamedResponse
    {
        // Re-authorised at OPEN, not at mint. This is the line that closes the
        // withdrawn-reader window.
        $this->authorize('view', $submission);

        $reader = $this->currentUser($request);

        // The signature named a reader; this request carries one. A mismatch is
        // a link that travelled, and it is refused as if it had never existed —
        // 404 rather than 403, because "wrong person" is itself information.
        abort_unless($request->string('reader')->toString() === $reader->uuid, 404);

        $media = $submission->file();

        abort_if($media === null, 404);

        return $media->toInlineResponse($request);
    }
}
