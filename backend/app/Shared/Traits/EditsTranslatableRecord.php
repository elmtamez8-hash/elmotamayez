<?php

declare(strict_types=1);

namespace App\Shared\Traits;

/**
 * Fill a Filament edit form from a translatable column without handing the
 * browser the whole document.
 *
 * ⚠️ THIS IS A DATA-DESTROYING BUG WITHOUT IT, NOT A COSMETIC ONE, AND IT WAS
 * FOUND BY OPENING THE SCREEN ON PRODUCTION. `EditRecord::fillForm()` fills from
 * `$record->attributesToArray()`, and spatie overrides `mutateAttributeForArray()`
 * to return the FULL translations array for a translatable attribute — so a
 * `TextInput` received `['ar' => 'الكيمياء']` and rendered the literal string
 * **`[object Object]`**, which is what JavaScript makes of an object in a text
 * field. Press «حفظ التغييرات» without touching the field and that string is
 * what gets saved: the subject is renamed `[object Object]` for everybody, from
 * a form the operator never edited.
 *
 * The table was correct the whole time (a column reads the accessor, which
 * answers one locale), which is exactly why nothing looked wrong until an edit
 * form was opened.
 *
 * ⚠️ AND ONLY THE FILL NEEDS FIXING. `HasTranslations::setAttribute()` already
 * takes both shapes — a string lands under the current locale, an associative
 * array replaces the whole document — so the save path was never broken and
 * needs no counterpart to this. Adding one would be a second answer to a
 * question the package already answers.
 *
 * ⚠️ AND THIS COMES OUT THE DAY A LANGUAGE-TABS COMPONENT GOES IN. A tabbed
 * editor wants the whole document — one input per locale — so flattening it on
 * fill is exactly what would break it. `pixelpeter/filament-language-tabs` is
 * the obvious candidate and its newest release requires `filament/filament ^4.0`
 * against our 5.7.6 (measured 2026-09-10: `composer require --dry-run` refuses
 * all three of its majors), so this stays until that catches up — which is
 * likely the same day a second language makes the tabs worth having.
 */
trait EditsTranslatableRecord
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! method_exists($record, 'getTranslatableAttributes')) {
            return $data;
        }

        $locale = app()->getLocale();

        foreach ($record->getTranslatableAttributes() as $attribute) {
            if (! array_key_exists($attribute, $data) || ! is_array($data[$attribute])) {
                continue;
            }

            // The locale's own string, or nothing — never another language's,
            // because a form pre-filled with a fallback saves that fallback into
            // the locale being edited and silently overwrites it.
            $data[$attribute] = $data[$attribute][$locale] ?? null;
        }

        return $data;
    }
}
