<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Question;
use App\Modules\Courses\Models\Course;
use Illuminate\Support\Facades\Artisan;

/*
| Meilisearch refuses a filter on an attribute its index was not told about,
| and the suite runs `SCOUT_DRIVER=null`, so no search test can see that — on
| production every bank and course search answered 500 (2026-09-24). This
| reads each `->where()` the two search builders send to the engine and fails
| over one that `scout.meilisearch.index-settings` does not declare.
*/

dataset('engine filters', [
    'bank' => [Question::class, 'app/Modules/Assessments/Support/BankSearch.php', '$builder'],
    'courses' => [Course::class, 'app/Modules/Courses/Http/Controllers/CourseController.php', '$search'],
]);

it('declares every attribute the search engine is asked to filter on', function (string $model, string $file, string $var): void {
    preg_match_all('/'.preg_quote($var, '/')."->where\\('([a-z_]+)'/", (string) file_get_contents(base_path($file)), $m);

    // The scan must find something, or it passes over a file it cannot read.
    expect($m[1])->not->toBeEmpty();

    $declared = config("scout.meilisearch.index-settings.{$model}.filterableAttributes", []);

    expect(array_values(array_diff(array_unique($m[1]), $declared)))->toBe([]);
})->with('engine filters');

it('lets Scout read each searchable model index name from outside the model', function (): void {
    // A protected override of the trait's public method is a BadMethodCallException
    // on every search — which course search was until 2026-09-24.
    foreach ([Question::class, Course::class] as $model) {
        expect((new ReflectionMethod($model, 'searchableAs'))->isPublic())->toBeTrue();
    }
});

it('lists every searchable model in the index settings the deploy rebuilds from', function (): void {
    /*
    | `search:rebuild-indexes` (run by `scripts/deploy.sh` when the Meilisearch
    | version moves to an empty data directory) imports exactly the models keyed
    | in `scout.meilisearch.index-settings`. A model that uses `Searchable` and
    | is missing there would come back from an upgrade with an empty index and
    | nothing saying why.
    */
    $searchable = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^use Laravel\\\\Scout\\\\Searchable;/m', $source) === 1
            && preg_match('/^\s+use [^;]*\bSearchable\b[^;]*;/m', $source) === 1
            && preg_match('/^namespace ([^;]+);/m', $source, $ns) === 1) {
            $searchable[] = $ns[1].'\\'.$file->getBasename('.php');
        }
    }

    // The scan must find something, or it passes over a tree it cannot read.
    expect($searchable)->not->toBeEmpty()
        ->and(array_values(array_diff($searchable, array_keys((array) config('scout.meilisearch.index-settings')))))->toBe([]);
});

it('registers the command the deploy calls to rebuild the indexes', function (): void {
    expect(array_key_exists('search:rebuild-indexes', Artisan::all()))->toBeTrue();
});
