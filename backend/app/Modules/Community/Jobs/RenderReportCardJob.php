<?php

declare(strict_types=1);

namespace App\Modules\Community\Jobs;

use App\Modules\Community\Models\ReportCard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\View;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Render one published card to a printable Arabic PDF (FR-039).
 *
 * ⚠️ THE FONT IS SHIPPED WITH THE REPOSITORY, and the reason is not licensing.
 * The frontend loads Cairo from Google Fonts, which does nothing whatsoever for a
 * server with no browser: mPDF needs the `.ttf` on disk to embed it, and without
 * an Arabic face every glyph in the document is a hollow box. `Cairo-Regular.ttf`
 * sits under `resources/fonts/` with its OFL licence beside it.
 *
 * ⚠️ AND `SC-013` IS NOT MEASURED BY EXTRACTING THE TEXT. Extraction reads the
 * encoding and the embedding, both of which are correct on a document whose
 * letters are printed disconnected and in reverse — the exact failure Arabic
 * shaping produces when `autoArabic` is off. The measurement is a human opening
 * the file, and it is a checklist item in `quickstart.md` §ج for that reason.
 */
class RenderReportCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $reportCardId)
    {
        $this->onQueue('community');
    }

    public function handle(): void
    {
        $card = ReportCard::query()
            ->with(['student:id,first_name,last_name', 'segments.teacher:id,first_name,last_name'])
            ->find($this->reportCardId);

        if ($card === null) {
            return;
        }

        $defaultConfig = (new ConfigVariables)->getDefaults();
        $defaultFontConfig = (new FontVariables)->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            // ⚠️ NAMED, OR mPDF WRITES ITS FONT CACHE INSIDE `vendor/`. That
            // works on a developer's checkout and is the classic first-render
            // failure on a deploy where the vendor tree is read-only — and the
            // failure arrives on the first real card, not at boot.
            'tempDir' => storage_path('app/mpdf'),
            // ⚠️ `fontDir` APPENDS to the vendor's own directories rather than
            // replacing them: mPDF still needs its bundled fallbacks for the
            // Latin digits and punctuation an Arabic document carries anyway.
            'fontDir' => array_merge($defaultConfig['fontDir'], [resource_path('fonts')]),
            'fontdata' => $defaultFontConfig['fontdata'] + [
                // ⚠️ THE KEY MUST BE LOWER CASE. mPDF looks the family up by a
                // lower-cased name, so `'Cairo'` here is a family that can be
                // declared in CSS and never found — and the failure is silent
                // substitution, not an error.
                'cairo' => ['R' => 'Cairo-Regular.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
            ],
            'default_font' => 'cairo',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_top' => 16,
            'margin_bottom' => 16,
        ]);

        // The three settings that make Arabic Arabic rather than a mirror of
        // itself: direction for the page, shaping for the letters, and the
        // language tag that picks the face.
        $mpdf->SetDirectionality('rtl');
        $mpdf->autoArabic = true;
        $mpdf->SetTitle('كشف التقديرات');

        $mpdf->WriteHTML((string) View::make('community.report-card', ['card' => $card])->render());

        $card
            ->addMediaFromString($mpdf->Output('', 'S'))
            ->usingFileName("report-card-{$card->uuid}.pdf")
            // ⚠️ THROUGH MEDIALIBRARY, NEVER A `file_path` COLUMN. A raw path
            // escapes the provider resolver, the 013 retention sweep and FR-036's
            // floor — a PDF carrying a minor's grades that nothing ever deletes.
            ->toMediaCollection('report_card_pdf');
    }
}
