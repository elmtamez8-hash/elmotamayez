<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

/**
 * Reads a PDF's page count and draws its pages as pictures (story 4). Bound to
 * {@see PopplerPdfPages}; the tests bind {@see FakePdfPages}, so CI needs no
 * poppler — the real commands run in the production image (backend.Dockerfile).
 */
interface PdfPages
{
    /** @throws PdfUnreadable a broken, encrypted or non-PDF file */
    public function count(string $pdfPath): int;

    /**
     * Pages 1..$pages as JPEGs 1920 px wide, in order.
     *
     * @return list<array{path: string, width: int, height: int}>
     *
     * @throws PdfUnreadable
     */
    public function render(string $pdfPath, string $directory, int $pages): array;
}
