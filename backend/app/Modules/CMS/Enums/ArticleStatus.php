<?php

declare(strict_types=1);

namespace App\Modules\CMS\Enums;

/**
 * Whether an article is on the public blog — `cms_articles.status`.
 *
 * Two values and no third: a scheduled article is `Published` with a
 * `published_at` in the future, never a status of its own (see
 * `Article::publicListingConstraints()`).
 *
 * ⚠️ THE API PAYLOAD CARRIES `->value`, the same two strings it always did —
 * the enum is the model's type, not a change to what a client reads or sends.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسوّدة',
            self::Published => 'منشور',
        };
    }

    /**
     * `value => label`, for a panel select or filter.
     *
     * Keyed by the string rather than the case: a Filament edit form fills from
     * `attributesToArray()`, which serialises the enum to its value, so a select
     * keyed any other way opens blank on every edit.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
