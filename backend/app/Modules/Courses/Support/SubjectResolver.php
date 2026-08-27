<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Marketplace\Models\Subject;
use Illuminate\Validation\ValidationException;

/**
 * A subject uuid becomes the id a course stores — or the write is refused.
 *
 * ⚠️ IT LIVES IN AN ACTION'S REACH RATHER THAN IN A FORM RULE, because the
 * seeders and the Filament panel create courses with no request behind them —
 * the repository's standing rule that a business rule enforced only on the way in
 * is a rule with a second door. `SeedCommand` runs every seeder inside
 * `Model::unguarded()`, so `$fillable` protects the application and not them.
 *
 * ⚠️ AND IT THROWS A VALIDATION ERROR RATHER THAN RETURNING NULL. Null is exactly
 * the state that made every downstream subject filter empty for a year: the
 * column has been fillable since 007's pricing migration and no writer ever set
 * it, so 77 of 77 courses carried nothing and the marketplace's own subject facet
 * had nothing to group. A silent null here would restore that quietly.
 */
final class SubjectResolver
{
    public static function id(?string $uuid): int
    {
        $id = $uuid === null || $uuid === ''
            ? null
            : Subject::query()->where('uuid', $uuid)->value('id');

        if ($id === null) {
            // Keyed on the field the form sends, so the sentence lands under the
            // control the teacher has to change.
            throw ValidationException::withMessages(['subject' => 'اختر مادّة هذا الكورس.']);
        }

        return (int) $id;
    }
}
