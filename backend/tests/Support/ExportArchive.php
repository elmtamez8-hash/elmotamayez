<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Reads a generated export archive back out, for the tests that judge it.
 *
 * ⚠️ A CLASS RATHER THAN A PEST HELPER FUNCTION, and that is not a style
 * preference. A function declared in one test file is GLOBAL across the whole
 * suite — the collision that already cost this repository a rename when `enrol`
 * was declared twice — and four US3 test files need this one.
 */
final class ExportArchive
{
    /**
     * Every file in the archive, JSON decoded where it is JSON.
     *
     * @return array<string, mixed>
     */
    public static function read(DataRequest $request): array
    {
        $path = (string) $request->fresh()?->export_path;

        if ($path === '') {
            throw new RuntimeException('The request produced no archive.');
        }

        $local = Storage::disk(ComplianceSettings::exportDisk())->path($path);

        $zip = new ZipArchive;

        if ($zip->open($local) !== true) {
            throw new RuntimeException('The archive could not be opened: '.$local);
        }

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $contents = (string) $zip->getFromIndex($i);

            $files[$name] = str_ends_with($name, '.json')
                ? json_decode($contents, true)
                : $contents;
        }

        $zip->close();

        return $files;
    }

    /**
     * The whole archive as one searchable string.
     *
     * ⚠️ RE-ENCODED WITH `JSON_UNESCAPED_UNICODE`. `json_encode` escapes non-ASCII
     * by default, so `expect($text)->not->toContain('حل زميلي')` never matches
     * whatever the archive holds — the encoded text carries `حل`. Every
     * leak assertion in this product is about Arabic, so without this the whole
     * family of tests would pass against an archive that leaked everything.
     */
    public static function text(DataRequest $request): string
    {
        return (string) json_encode(
            self::read($request),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The rows of one category file.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(DataRequest $request, string $category): array
    {
        $files = self::read($request);
        $rows = $files['data/'.$category.'.json'] ?? null;

        return is_array($rows) ? $rows : [];
    }
}
