<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

/**
 * The tests' PDF reader: says the file has `$pages` pages and writes that many
 * small files standing for the pictures, each 1920 × `$height`. `$corrupt` makes
 * it refuse the file like poppler would.
 */
final class FakePdfPages implements PdfPages
{
    public int $renders = 0;

    public function __construct(public int $pages = 3, public int $height = 1080, public bool $corrupt = false) {}

    public function count(string $pdfPath): int
    {
        if ($this->corrupt) {
            throw new PdfUnreadable('corrupt');
        }

        return $this->pages;
    }

    public function render(string $pdfPath, string $directory, int $pages): array
    {
        $this->renders++;
        $out = [];
        for ($i = 1; $i <= $pages; $i++) {
            $path = $directory.DIRECTORY_SEPARATOR."page-{$i}.jpg";
            file_put_contents($path, "fake page {$i}");
            $out[] = ['path' => $path, 'width' => 1920, 'height' => $this->height];
        }

        return $out;
    }
}
