<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Models\Course;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Refuses a write computed against a tree that has since changed.
 *
 * Answered with 409 and the current version, not 422: nothing about the request
 * was malformed. The client's map is out of date, and the fix is to re-read the
 * tree — a different instruction to the user than "correct your input".
 *
 * Shared by reorder and publish because both are whole-tree writes sent from a
 * map the client drew earlier. Two copies of the comparison would eventually
 * disagree about which one 409s.
 */
final class StructureVersion
{
    public static function assertMatches(Course $course, int $submitted): void
    {
        if ($submitted === (int) $course->structure_version) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'تغيّرت الشجرة منذ فتحتها. أعد تحميلها قبل الحفظ حتى لا يُدهس تعديل غيرك.',
            'structure_version' => $course->structure_version,
        ], 409));
    }
}
