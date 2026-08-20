<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Create or update one catalogue entry.
 *
 * ⚠️ THE BOUNDS ARE ENFORCED HERE, NOT ONLY IN THE FORM REQUEST, and this phase
 * has two doors that skip validation entirely: `SeedCommand` runs every seeder
 * inside `Model::unguarded()`, and the Filament panel writes without a
 * `FormRequest` at all. A rule that lives only in validation is a rule with two
 * known bypasses — Constitution II says the Action is the single entrance the
 * seeders, the panel and the API all share.
 */
class SaveDataCategory extends Action
{
    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes, ?DataCategory $category = null): DataCategory
    {
        $this->guardRetention($attributes);

        if ($category === null) {
            return DataCategory::query()->create($attributes);
        }

        $category->fill($attributes)->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guardRetention(array $attributes): void
    {
        $days = $attributes['retain_days'] ?? null;

        if ($days === null) {
            return;
        }

        $days = (int) $days;

        /*
        | ⚠️ ZERO IS THE ONE TYPO THAT ERASES THE PLATFORM IN A NIGHT. It reads as
        | "no retention" and means "everything older than this instant", which is
        | everything — and the sweep is not reversible.
        */
        if ($days < ComplianceSettings::minRetainDays()) {
            throw new DomainException('مدّةُ الاحتفاظ لا تقلّ عن يومٍ واحد: الصفرُ ليس «فوراً» بل محوُ كلّ ما مضى في ليلة.');
        }

        /*
        | ⚠️ AND THE CEILING IS THE COLUMN, NOT A PREFERENCE. `unsignedSmallInteger`
        | stops at 65,535; past it `created_at + n days` runs off the end of the
        | calendar and MySQL raises ERROR 1441, which kills the whole sweep — every
        | other category included. SQLite returns NULL instead and nothing expires,
        | so neither failure appears on a development machine.
        */
        if ($days > ComplianceSettings::maxRetainDays()) {
            throw new DomainException('أقصى مدّةِ احتفاظٍ ٦٥٥٣٥ يوماً (‏نحو ١٧٩ سنة). اتركِ الحقلَ فارغاً إن كان الصنفُ لا ينقضي.');
        }
    }
}
