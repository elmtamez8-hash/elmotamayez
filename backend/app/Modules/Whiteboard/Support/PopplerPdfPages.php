<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use Illuminate\Support\Facades\Process;

/**
 * poppler-utils (GPL, run unmodified as a command): `pdfinfo` counts, `pdftoppm`
 * draws. Arguments are an ARRAY — never a shell string — so a filename can carry
 * nothing into a command line (the file is ours anyway, named by uuid).
 *
 * Every page is drawn 1920 px WIDE (`-scale-to-x 1920 -scale-to-y -1`, not
 * `-scale-to 1920`, which scales the LONGER side and left a portrait A4 1358 px
 * wide): the board's page is 1920 wide and grows downward in screens, so a
 * portrait page becomes a page of three screens and a 16:9 slide exactly one.
 */
final class PopplerPdfPages implements PdfPages
{
    /** Each command's ceiling — below the job's 600 s timeout, with room. */
    private const COUNT_SECONDS = 60;

    private const RENDER_SECONDS = 480;

    public function count(string $pdfPath): int
    {
        $result = Process::timeout(self::COUNT_SECONDS)->run([$this->bin('pdfinfo'), $pdfPath]);
        if (! $result->successful() || preg_match('/^Pages:\s+(\d+)/m', $result->output(), $m) !== 1) {
            throw new PdfUnreadable('pdfinfo could not read the file.');
        }

        return (int) $m[1];
    }

    public function render(string $pdfPath, string $directory, int $pages): array
    {
        $result = Process::timeout(self::RENDER_SECONDS)->run([
            $this->bin('pdftoppm'),
            '-f', '1', '-l', (string) $pages,
            '-scale-to-x', '1920', '-scale-to-y', '-1',
            '-jpeg', '-jpegopt', 'quality=85',
            $pdfPath, $directory.DIRECTORY_SEPARATOR.'page',
        ]);
        if (! $result->successful()) {
            throw new PdfUnreadable('pdftoppm could not draw the pages.');
        }

        // `page-01.jpg` … `page-100.jpg`: the number's width follows the page
        // count, so the order is the NUMBER's, never the name's.
        $files = glob($directory.DIRECTORY_SEPARATOR.'page-*.jpg') ?: [];
        usort($files, fn (string $a, string $b): int => self::number($a) <=> self::number($b));

        return array_map(function (string $path): array {
            $size = getimagesize($path);
            if ($size === false) {
                throw new PdfUnreadable('A drawn page is not a picture.');
            }

            return ['path' => $path, 'width' => (int) $size[0], 'height' => (int) $size[1]];
        }, $files);
    }

    private function bin(string $name): string
    {
        $dir = (string) config('whiteboard.poppler_path');

        return $dir === '' ? $name : rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
    }

    private static function number(string $path): int
    {
        return preg_match('/page-(\d+)\.jpg$/', $path, $m) === 1 ? (int) $m[1] : 0;
    }
}
