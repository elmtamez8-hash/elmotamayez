<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Models\DataProcessor;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Create or update one entry in the processor register.
 *
 * ⚠️ THE CATEGORIES ARE CHECKED AGAINST THE CATALOGUE HERE, for the reason
 * {@see SaveDataCategory} gives: the seeders and the panel both reach this class
 * with no form behind them. A processor declaring a category that does not exist
 * is a register that answers `FR-024` — "what did this third party receive" —
 * with a key nothing can resolve.
 */
class SaveDataProcessor extends Action
{
    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes, ?DataProcessor $processor = null): DataProcessor
    {
        $this->guardCategories($attributes);

        if ($processor === null) {
            return DataProcessor::query()->create($attributes);
        }

        $processor->fill($attributes)->save();

        return $processor;
    }

    /** @param array<string, mixed> $attributes */
    private function guardCategories(array $attributes): void
    {
        $declared = $attributes['categories'] ?? null;

        if (! is_array($declared) || $declared === []) {
            return;
        }

        $known = DataCategory::query()->whereIn('key', $declared)->pluck('key')->all();
        $unknown = array_values(array_diff($declared, $known));

        if ($unknown !== []) {
            throw new DomainException('أصنافٌ غيرُ معرَّفةٍ في الكتالوج: '.implode('، ', $unknown));
        }
    }
}
