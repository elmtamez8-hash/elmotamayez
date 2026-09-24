<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * `Course::searchableAs()` was protected, so Scout could not read the index
 * name from outside the model: course search threw BadMethodCallException and
 * the previous migration's settings/import never reached `courses_index`.
 * Run both again now that it is public.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        Artisan::call('scout:sync-index-settings');
        Artisan::call('scout:import', ['model' => Course::class]);
    }

    public function down(): void
    {
        // The engine's index, not the database's.
    }
};
