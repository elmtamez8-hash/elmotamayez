<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Store\Models\StoreItem;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;

/**
 * A product's cover picture, on the `public` disk — the account photo's shape
 * (`SaveAccountPhoto`): a random name so a changed cover is never served from
 * cache, always re-encoded as JPEG, and the previous file deleted. A null file
 * removes the cover.
 *
 * 600×800: a book's portrait proportion (3:4).
 */
class SaveStoreCover extends Action
{
    public const DIRECTORY = 'store-covers';

    private const WIDTH = 600;

    private const HEIGHT = 800;

    public function handle(StoreItem $item, ?UploadedFile $file): StoreItem
    {
        $previous = $item->cover_path;
        $path = null;

        if ($file !== null) {
            $stored = $file->storeAs(self::DIRECTORY, $item->uuid.'-'.Str::random(8).'.jpg', 'public');

            if ($stored === false) {
                throw new DomainException('تعذّر حفظ الصورة. حاول مرة أخرى.');
            }

            $absolute = Storage::disk('public')->path($stored);

            Image::useImageDriver(ImageDriver::Gd)
                ->loadFile($absolute)
                ->fit(Fit::Crop, self::WIDTH, self::HEIGHT)
                ->quality(85)
                ->save($absolute);

            $path = $stored;
        }

        $item->forceFill(['cover_path' => $path])->save();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $item;
    }
}
