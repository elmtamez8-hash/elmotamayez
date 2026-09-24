<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Question;
use App\Modules\Courses\Models\Course;

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
