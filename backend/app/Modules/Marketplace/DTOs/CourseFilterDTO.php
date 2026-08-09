<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\DTOs;

use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Data\DataTransferObject;

/**
 * No rating filter: courses have no reviews of their own yet, and a filter that
 * silently matches everything is worse than an absent one. Add it with course
 * reviews, not before.
 */
class CourseFilterDTO extends DataTransferObject
{
    public const SORT_POPULAR = 'popular';

    public const SORT_NEWEST = 'newest';

    public function __construct(
        public readonly ?string $subject = null,
        public readonly ?string $gradeLevel = null,
        public readonly ?string $type = null,
        public readonly ?string $teacher = null,
        public readonly string $sort = self::SORT_POPULAR,
        public readonly int $page = 1,
        public readonly int $perPage = 12,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array{default_per_page: int, max_per_page: int} $limits */
        $limits = config('marketplace.pagination');

        $perPage = (int) ($data['per_page'] ?? $limits['default_per_page']);

        return new self(
            subject: $data['subject'] ?? null,
            gradeLevel: $data['grade_level'] ?? null,
            type: $data['type'] ?? null,
            teacher: $data['teacher'] ?? null,
            sort: $data['sort'] ?? self::SORT_POPULAR,
            page: max(1, (int) ($data['page'] ?? 1)),
            perPage: max(1, min($perPage, $limits['max_per_page'])),
        );
    }

    /** @return array<string, string> */
    public function toFilterMap(): array
    {
        return array_filter([
            'subject' => $this->subject,
            'grade_level' => $this->gradeLevel,
            'type' => $this->type,
            'teacher' => $this->teacher,
            'sort' => $this->sort,
        ], fn (?string $value) => $value !== null);
    }

    public function cacheKey(): string
    {
        return MarketplaceCache::key('courses:'.md5(serialize([
            $this->toFilterMap(), $this->page, $this->perPage,
        ])));
    }
}
