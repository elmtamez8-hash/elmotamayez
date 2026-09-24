<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Question;
use App\Modules\Courses\Models\Course;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Push `scout.meilisearch.index-settings` to the engine, then re-import both
 * searchable models.
 *
 * The settings never reached production, so every filtered search answered
 * 500. The import is here too because the engine was unreachable for part of
 * August (`failed_jobs` holds the refused index writes), so rows written then
 * are missing from it. `scout:import` upserts, so running it twice is harmless.
 *
 * Only on Meilisearch: the suite's `null` driver cannot hold settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        Artisan::call('scout:sync-index-settings');

        foreach ([Question::class, Course::class] as $model) {
            Artisan::call('scout:import', ['model' => $model]);
        }
    }

    public function down(): void
    {
        // Settings and documents are the engine's; nothing to undo in the database.
    }
};
