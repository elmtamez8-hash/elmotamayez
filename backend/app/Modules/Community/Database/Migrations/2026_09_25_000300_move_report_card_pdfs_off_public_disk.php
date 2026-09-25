<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The report-card and certificate PDFs written before their collections were
 * pinned to `local` sit on the PUBLIC disk, which nginx serves at `/storage/`
 * with no authentication — measured on production 2026-09-25, an anonymous GET
 * answered 200 with a named minor's grades. Pinning the disk fixes new files
 * only; this moves the ones already written.
 *
 * `DB::table()`, never the Media model: a migration speaks the schema of its own
 * date. The path is medialibrary's default generator (`{id}/{file_name}`) — the
 * app publishes no custom one. A row whose file is missing is still moved to
 * `local`: pointing it at the public disk would keep advertising a URL, and the
 * renderer writes a fresh file on the next publish either way.
 *
 * Idempotent: it selects `disk = 'public'` only, so a re-run finds nothing.
 */
return new class extends Migration
{
    private const COLLECTIONS = ['report_card_pdf', 'certificate_pdf'];

    public function up(): void
    {
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        DB::table('media')
            ->whereIn('collection_name', self::COLLECTIONS)
            ->where('disk', 'public')
            ->orderBy('id')
            ->get(['id', 'file_name'])
            ->each(function (object $media) use ($public, $local): void {
                $path = $media->id.'/'.$media->file_name;

                if ($public->exists($path)) {
                    $local->put($path, (string) $public->get($path));
                }

                DB::table('media')->where('id', $media->id)->update([
                    'disk' => 'local',
                    'conversions_disk' => 'local',
                ]);

                // Deleted only after the row points at the copy, so a failure in
                // between leaves a readable file behind rather than none.
                $public->deleteDirectory((string) $media->id);
            });
    }

    /** Not reversed: putting a minor's PDF back on a public URL is not a rollback anyone wants. */
    public function down(): void {}
};
