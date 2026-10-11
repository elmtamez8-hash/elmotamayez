<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * How many rows each public list shows per page — the store, the courses, the
 * teachers, a teacher's reviews — editable from «إعدادات المنصّة» without a
 * deploy (owner request 2026-10-11).
 *
 * Operational numbers live in `platform_settings`, never `config/`. Each one is
 * clamped to 1..MAX, the same ceiling the list requests accept as `per_page`, so
 * a number typed in the panel can never ask the database for an unbounded page.
 */
final class ListingPageSizes
{
    public const MAX = 48;

    /** key => [label, default] — the panel draws one field per row. */
    public const FIELDS = [
        'store' => ['المتجر — منتجات في الصفحة', 24],
        'courses' => ['الكورسات — كورسات في الصفحة', 12],
        'teachers' => ['المدرّسون — مدرّسون في الصفحة', 12],
        'reviews' => ['تقييمات المدرّس — تقييمات في الصفحة', 10],
    ];

    public static function store(): int
    {
        return self::read('store');
    }

    public static function courses(): int
    {
        return self::read('courses');
    }

    public static function teachers(): int
    {
        return self::read('teachers');
    }

    public static function reviews(): int
    {
        return self::read('reviews');
    }

    public static function key(string $list): string
    {
        return 'marketplace.page_size.'.$list;
    }

    public static function clamp(int $value): int
    {
        return max(1, min(self::MAX, $value));
    }

    private static function read(string $list): int
    {
        return self::clamp((int) PlatformSettings::get(self::key($list), self::FIELDS[$list][1]));
    }
}
