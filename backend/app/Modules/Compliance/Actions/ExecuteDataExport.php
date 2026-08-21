<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Modules\Compliance\Support\DataSubjectResolver;
use App\Modules\Compliance\Support\ExportFieldAllowlist;
use App\Modules\Compliance\Support\PersonalDataRegistry;
use App\Shared\Actions\Action;
use App\Shared\Contracts\PersonalDataOwner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Everything the platform holds about one person, in one archive (FR-016 · SC-014).
 *
 * ⚠️ NOTHING IS EVER HELD WHOLE. Three separate decisions keep the peak memory of a
 * fifty-thousand-row export flat, and dropping any one of them undoes the other
 * two:
 *
 *  1. Each implementor's `export()` is a GENERATOR that yields PAGES. The same
 *     category key may arrive many times — that is how the heaviest tables stay
 *     under the ceiling — so this Action appends rather than assigns.
 *  2. Each page is encoded and written to its own temp file IMMEDIATELY. Building
 *     one array per category and encoding it at the end peaks at twice the
 *     serialised size, and `supervisor-compliance` runs with a memory ceiling and
 *     `tries: 1`: an out-of-memory kill is not retried, it is a request stuck in
 *     `processing` until the sweep finds it.
 *  3. The zip is assembled with `addFile()`, never `addFromString()`.
 *     `addFromString` BUFFERS ITS CONTENT IN RAM until `close()`, so thirteen
 *     categories added that way would defeat points 1 and 2 entirely at the last
 *     line. `addFile()` defers the read to `close()`, which streams.
 *
 * ⚠️ AND THE JSON IS WRITTEN AS AN ARRAY BY HAND — an opening bracket, rows
 * separated by commas, a closing bracket — rather than as newline-delimited
 * records. FR-016 asks for machine AND human readable, and a person opening
 * `payment_record.json` in any editor should see a document, not a log.
 */
class ExecuteDataExport extends Action
{
    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly DataSubjectResolver $resolver,
    ) {}

    /**
     * @return array{path: string, bytes: int}
     */
    public function handle(DataRequest $request): array
    {
        $subject = $this->resolver->forRequest($request);

        $workDirectory = storage_path('app/compliance-export/'.$request->uuid);

        if (! is_dir($workDirectory) && ! mkdir($workDirectory, 0775, true) && ! is_dir($workDirectory)) {
            throw new RuntimeException('Unable to create the export working directory.');
        }

        /** @var array<string, resource> $handles */
        $handles = [];
        /** @var array<string, int> $counts */
        $counts = [];

        try {
            foreach ($this->registry->all() as $owner) {
                foreach ($owner->export($subject) as $category => $rows) {
                    if ($rows === []) {
                        // Still open the file: an empty `payment_record.json` says
                        // "we hold nothing here", which is an answer. A missing file
                        // says nothing at all, and the difference is the whole
                        // point of a rights request.
                        $this->openHandle($handles, $counts, $workDirectory, (string) $category);

                        continue;
                    }

                    $this->writePage($handles, $counts, $workDirectory, (string) $category, $rows, $owner);
                }
            }

            foreach ($handles as $handle) {
                fwrite($handle, "\n]\n");
                fclose($handle);
            }

            $handles = [];

            return $this->pack($request, $workDirectory, $counts);
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }

            $this->removeDirectory($workDirectory);
        }
    }

    /**
     * @param  array<string, resource>  $handles
     * @param  array<string, int>  $counts
     * @return resource
     */
    private function openHandle(array &$handles, array &$counts, string $directory, string $category)
    {
        if (isset($handles[$category])) {
            return $handles[$category];
        }

        $handle = fopen($directory.'/'.$category.'.json', 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the export file for '.$category.'.');
        }

        fwrite($handle, "[\n");

        $counts[$category] = 0;

        return $handles[$category] = $handle;
    }

    /**
     * @param  array<string, resource>  $handles
     * @param  array<string, int>  $counts
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writePage(
        array &$handles,
        array &$counts,
        string $directory,
        string $category,
        array $rows,
        PersonalDataOwner $owner,
    ): void {
        $handle = $this->openHandle($handles, $counts, $directory, $category);

        foreach ($rows as $row) {
            $leaks = ExportFieldAllowlist::leaks($row);

            if ($leaks !== []) {
                /*
                | ⚠️ LOGGED AND STRIPPED, NEVER THROWN. One forbidden key in one
                | module would otherwise fail a LEGAL request for the entire
                | archive, which is a worse outcome than the field it prevents; and
                | dropping it silently would hide the defect for ever. The module
                | is named so the line leads somewhere.
                */
                Log::warning('compliance.export.forbidden_field', [
                    'module' => $owner->moduleKey(),
                    'category' => $category,
                    'paths' => $leaks,
                ]);

                $row = ExportFieldAllowlist::strip($row);
            }

            if ($counts[$category] > 0) {
                fwrite($handle, ",\n");
            }

            fwrite($handle, (string) json_encode(
                $row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));

            $counts[$category]++;
        }
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{path: string, bytes: int}
     */
    private function pack(DataRequest $request, string $directory, array $counts): array
    {
        $zipPath = $directory.'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the export archive.');
        }

        ksort($counts);

        foreach (array_keys($counts) as $category) {
            // `addFile`, not `addFromString` — see the class docblock.
            $zip->addFile($directory.'/'.$category.'.json', 'data/'.$category.'.json');
        }

        // These two are small by construction, so buffering them costs nothing.
        $zip->addFromString('README.md', $this->readme($request, $counts));
        $zip->addFromString('manifest.json', (string) json_encode([
            'request_uuid' => $request->uuid,
            'type' => $request->type->value,
            'generated_at' => now()->toIso8601String(),
            'categories' => $counts,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $zip->close();

        $disk = Storage::disk(ComplianceSettings::exportDisk());
        $target = 'compliance/exports/'.$request->uuid.'.zip';

        $stream = fopen($zipPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to read the export archive.');
        }

        // Streamed onto the disk rather than read into a string: the archive is the
        // one object in this product whose size is bounded only by how long the
        // person has been a student.
        $disk->put($target, $stream);
        fclose($stream);

        $bytes = (int) filesize($zipPath);
        @unlink($zipPath);

        return ['path' => $target, 'bytes' => $bytes];
    }

    /**
     * The human half of "machine-readable and human-readable" (FR-016).
     *
     * @param  array<string, int>  $counts
     */
    private function readme(DataRequest $request, array $counts): string
    {
        $lines = [
            '# نسخةٌ من بياناتك',
            '',
            'هذا الملفُّ يحوي ما تحتفظ به المنصّةُ عنك، مصنَّفاً حسب نوع البيانات.',
            'كلُّ ملفٍّ داخل مجلَّد `data/` هو قائمةُ سجلّاتٍ بصيغة JSON، ويمكن فتحُه',
            'بأيّ محرِّر نصوص. الملفُّ الفارغ (`[]`) يعني أنّنا لا نحتفظ بشيءٍ من هذا',
            'النوع عنك — وهو جوابٌ مقصود، لا نقصٌ في التصدير.',
            '',
            '## الملفّات',
            '',
        ];

        foreach ($counts as $category => $count) {
            $lines[] = '- `data/'.$category.'.json` — '.$count.' سجلّاً';
        }

        $lines[] = '';
        $lines[] = '## ما لا تجده هنا، ولماذا';
        $lines[] = '';
        $lines[] = '- **صورةُ إيصال التحويل**: قد تحمل اسمَ صاحب حسابٍ بنكيٍّ ثالثٍ ورقمَه،';
        $lines[] = '  فنذكر وجودَ الإيصال وتاريخَه ولا نُضمِّن الصورة.';
        $lines[] = '- **بياناتُ أشخاصٍ آخرين**: زملاؤك ومدرّسوك أصحابُ بياناتهم، ولهم الحقُّ';
        $lines[] = '  نفسُه الذي مارستَه بهذا الطلب.';
        $lines[] = '- **كلماتُ المرور والرموزُ السرّية**: مخزَّنةٌ مجزَّأةً ولا يمكن استرجاعُها،';
        $lines[] = '  ولا يجوز أن تُنقل في ملفٍّ يُنزَّل.';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'رقمُ الطلب: `'.$request->uuid.'`';
        $lines[] = 'تاريخُ التوليد: '.now()->toDateTimeString();

        return implode("\n", $lines)."\n";
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
