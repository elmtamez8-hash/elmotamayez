<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Actions;

use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Certificates\Support\CertificateDesignLimits;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;

/**
 * A teacher's own certificate artwork.
 *
 * ⚠️ RE-ENCODED ON THE SERVER BEFORE IT IS STORED (`FR-042`), on the model of
 * `SaveAccountPhoto` line for line — and the reasons bite harder here, because
 * this image is served to every visitor who opens a verification link:
 *
 *   ١. **EXIF is erased**, GPS coordinates included. A design photographed or
 *      exported from a phone carries where it was made.
 *   ٢. **A polyglot file dies.** The `public` disk is served directly, and a file
 *      with a correct header and a payload in its tail passes `mimes:` — it does
 *      not survive a re-encode that reads pixels and writes them out again.
 *   ٣. **The size becomes a limit rather than a hope.** `max:` is what is accepted
 *      at the door; this is what is actually stored.
 *
 * ⚠️ `Gd` NAMED EXPLICITLY: this server has no `imagick` (measured 2026-09-06) and
 * the package picks its own default, so relying on it works on one machine and
 * throws on another. And no `optimize()` — it shells out to programs that are not
 * on every server.
 *
 * ⚠️ AND `Fit::Max`, NEVER `Fit::Crop`. Cropping cuts the design's ornament off,
 * which is the whole thing the teacher chose. `Max` carries `DoNotUpsize` too, so
 * a small file is left at its own size rather than blown up into a blurry sheet.
 */
class UploadCertificateDesign extends Action
{
    /** One directory on the `public` disk, so it is easy to sweep. */
    public const DIRECTORY = 'certificate-designs';

    /** The shipped templates are 1491px wide; SC-011 asks for two seconds. */
    private const MAX_SIDE = 1600;

    private const QUALITY = 82;

    public function handle(int $workspaceId, UploadedFile $file, string $name): CertificateDesign
    {
        $limit = CertificateDesignLimits::uploadLimit();
        $used = $this->uploadsIn($workspaceId);

        // ⚠️ Counted in the Action, not only in the request: the message has to say
        // the number and offer the way out (`FR-046`).
        if ($used >= $limit) {
            throw new DomainException(
                "بلغتَ الحدّ الأعلى للتصاميم المرفوعة ({$limit}). احذف تصميماً قبل رفع آخر.",
            );
        }

        /*
        | ⚠️ THE STORED EXTENSION IS FIXED AND MATCHES WHAT IS ENCODED, never
        | `$file->extension()`. Spatie picks the output format from the path, and a
        | name ending `.png` over WebP bytes is the type every static server
        | guesses from the name. A random basename rather than a predictable one so
        | a replaced design is not served from a browser or CDN cache.
        */
        $stored = $file->storeAs(self::DIRECTORY, Str::uuid()->toString().'.webp', 'public');

        if ($stored === false) {
            throw new DomainException('تعذّر حفظ الصورة. حاول مرة أخرى.');
        }

        $this->normalise(Storage::disk('public')->path($stored));

        $design = new CertificateDesign(['name' => $name]);

        /*
        | ⚠️ `field_boxes` STAYS NULL, WHICH IS WHAT «NOT READY» MEANS (`FR-044`).
        | An image with no field positions would print the student's name over the
        | ornament, and the first person to see that is the student on their own
        | certificate — so it cannot be selected until somebody has said where the
        | six fields go.
        */
        $design->forceFill([
            'workspace_id' => $workspaceId,
            'image_path' => $stored,
        ])->save();

        return $design;
    }

    private function uploadsIn(int $workspaceId): int
    {
        return CertificateDesign::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereNull('system_key')
            ->count();
    }

    private function normalise(string $absolutePath): void
    {
        Image::useImageDriver(ImageDriver::Gd)
            ->loadFile($absolutePath)
            ->fit(Fit::Max, self::MAX_SIDE, self::MAX_SIDE)
            ->quality(self::QUALITY)
            ->save($absolutePath);
    }
}
