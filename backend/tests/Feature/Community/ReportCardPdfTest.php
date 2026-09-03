<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Jobs\BuildReportCardsJob;
use App\Modules\Community\Jobs\RenderReportCardJob;
use App\Modules\Community\Models\GradingScheme;
use App\Modules\Community\Models\ReportCard;
use App\Modules\Courses\Models\Course;
use Illuminate\Support\Facades\Queue;

/*
| ⚠️ NOTHING IN THIS TREE HAD EVER CONSTRUCTED `Mpdf`, AND THAT IS WHY EVERY
| REPORT-CARD PDF FAILED IN PRODUCTION FROM THE DAY IT SHIPPED.
|
| `ReportCardFidelityTest` and `ReportCardSnapshotTest` both open with
| `Queue::fake([RenderReportCardJob::class])` — correct for what they measure, and
| between them it means the renderer is the one job in the module no test has ever
| run. Twelve rows in `failed_jobs`, every one of them:
|
|     ErrorException: Undefined array key 0 in vendor/mpdf/mpdf/src/TTFontFile.php:1535
|
| `resources/fonts/Cairo-Regular.ttf` was a **variable** font (axes `wght`
| 200–1000 and `slnt`), and a variable font carries the `rvrn` feature — Required
| Variation Alternates — whose lookup list is EMPTY. `_getGSUBtables()` does
| `$lg[$ft['LookupListIndex'][0]] = $ft;` and indexes `[0]` without asking. Bare
| PHP raises a Warning and carries on; Laravel's `HandleExceptions` turns that
| Warning into an `ErrorException`, so inside the app it throws — during
| `Mpdf::__construct()`, before a single line of the card is written.
|
| ⚠️ AND THE CACHE IS WHY NOBODY SAW IT — INCLUDING, FOR TEN MINUTES, THE FIX.
| `Mpdf::AddFont()` reads a cached `.mtx.php` from `tempDir` and skips
| `TTFontFile` **entirely** when one exists. A developer's `storage/app/mpdf`
| holds one from before the font was replaced, so the broken font renders happily
| for ever on the machine that has it, and throws on the first card a fresh
| container ever builds. The first run of this very fix "passed" against both the
| broken and the fixed font for exactly that reason.
|
| So this test DELETES THE CACHE FIRST. Without that line it is green whatever is
| in `resources/fonts/`, which is the shape it exists to refuse.
*/

beforeEach(function (): void {
    // The renderer is the subject here, so it is the ONE job not faked.
    Queue::fake([RenderReportCardJob::class]);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->student = User::factory()->create();
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    periodEnrollment($this->workspace, $this->course, $this->student);

    GradingScheme::factory()->create([
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'weights' => ['exams' => 100, 'homework' => 0, 'attendance' => 0, 'participation' => 0],
    ]);

    periodAttempt($this->workspace, $this->student, 90, 100);

    app()->call([new BuildReportCardsJob('2026-08-01', '2026-08-31'), 'handle']);
});

it('renders a card to a real PDF with the shipped Arabic font', function (): void {
    $card = ReportCard::query()->where('student_user_id', $this->student->getKey())->firstOrFail();

    /*
     * ⚠️ THE LOAD-BEARING LINE. mPDF parses the `.ttf` only when it has no cached
     * metrics for the family; with a cache present this test passes against a
     * font it never opened.
     */
    $cache = storage_path('app/mpdf');

    if (is_dir($cache)) {
        foreach ((array) glob($cache.'/*') as $stale) {
            if (is_string($stale) && is_file($stale)) {
                unlink($stale);
            }
        }
    }

    app()->call([new RenderReportCardJob($card->getKey()), 'handle']);

    $card->refresh();

    /*
     * The PDF goes through medialibrary, never a `file_path` column — a raw path
     * escapes the retention sweep, and this is a minor's grades. So the artefact
     * is the media row, and the assertion is on it rather than on the return of a
     * void method.
     */
    $media = $card->getMedia('report_card_pdf');

    expect($media)->toHaveCount(1);
    expect($media->first()->mime_type)->toBe('application/pdf');
    // A font mPDF refused would leave a document with no glyphs, not no bytes —
    // but the failure this guards throws before a byte is written, so a real size
    // is the honest floor.
    expect($media->first()->size)->toBeGreaterThan(1000);
});

it('ships a STATIC font, because mPDF cannot read a variable one', function (): void {
    $path = resource_path('fonts/Cairo-Regular.ttf');

    expect(file_exists($path))->toBeTrue();

    $blob = (string) file_get_contents($path);
    $count = unpack('n', substr($blob, 4, 2));
    $tables = [];

    for ($i = 0; $i < (int) ($count[1] ?? 0); $i++) {
        $tables[] = substr($blob, 12 + ($i * 16), 4);
    }

    /*
     * `fvar` is the axis table: present only in a variable font, and its presence
     * is what drags `rvrn` in with it. Asserting on the TABLE rather than on a
     * file size or a checksum means a future re-download of the wrong build fails
     * here with a sentence, instead of failing in a queue worker at 02:40.
     */
    expect($tables)->not->toContain('fvar');
    expect($tables)->not->toContain('gvar');
});
