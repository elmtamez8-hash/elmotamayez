<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\DTOs;

use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Data\DataTransferObject;

class TeacherFilterDTO extends DataTransferObject
{
    public const SORT_RATING = 'rating_desc';

    public const SORT_TRUST = 'trust_desc';

    public function __construct(
        public readonly ?string $subject = null,
        public readonly ?string $gradeLevel = null,
        public readonly ?float $minRating = null,
        public readonly ?int $minTrustScore = null,
        public readonly ?string $language = null,
        public readonly bool $availableNow = false,
        public readonly ?string $search = null,
        public readonly string $sort = self::SORT_RATING,
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
            minRating: isset($data['min_rating']) ? (float) $data['min_rating'] : null,
            minTrustScore: isset($data['min_trust_score']) ? (int) $data['min_trust_score'] : null,
            language: $data['language'] ?? null,
            availableNow: filter_var($data['available_now'] ?? false, FILTER_VALIDATE_BOOL),
            search: isset($data['q']) && $data['q'] !== '' ? (string) $data['q'] : null,
            sort: $data['sort'] ?? self::SORT_RATING,
            page: max(1, (int) ($data['page'] ?? 1)),
            perPage: max(1, min($perPage, $limits['max_per_page'])),
        );
    }

    /**
     * The filters echoed back to the client so the UI can render removable chips
     * without re-parsing the query string.
     *
     * @return array<string, string>
     */
    public function toFilterMap(): array
    {
        return array_filter([
            'subject' => $this->subject,
            'grade_level' => $this->gradeLevel,
            'min_rating' => $this->minRating !== null ? (string) $this->minRating : null,
            'min_trust_score' => $this->minTrustScore !== null ? (string) $this->minTrustScore : null,
            'language' => $this->language,
            'available_now' => $this->availableNow ? '1' : null,
            'q' => $this->search,
            'sort' => $this->sort,
        ], fn (?string $value) => $value !== null);
    }

    /**
     * Cache key for this exact filter combination.
     *
     * The version prefix means unpublishing a teacher invalidates every filter
     * permutation at once; enumerating them is impossible and missing one leaves a
     * suspended teacher visible.
     */
    public function cacheKey(): string
    {
        return MarketplaceCache::key('teachers:'.md5(serialize([
            $this->toFilterMap(), $this->page, $this->perPage,
        ])));
    }
}
