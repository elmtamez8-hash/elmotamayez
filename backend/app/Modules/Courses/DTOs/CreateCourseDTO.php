<?php

declare(strict_types=1);

namespace App\Modules\Courses\DTOs;

use App\Modules\Courses\Models\Course;
use App\Shared\Data\DataTransferObject;

class CreateCourseDTO extends DataTransferObject
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $slug = null,
        /** Minor units, always an integer. A float price is the defect 007 removed. */
        public readonly int $priceMinor = 0,
        public readonly string $currency = 'QAR',
        public readonly string $status = 'draft',
        public readonly string $visibility = 'private',
        public readonly bool $isSequential = true,
        /** The platform-wide subject uuid; resolved to an id in the Action. */
        public readonly ?string $subjectUuid = null,
        /** A `grade_levels` slug, stored as the undefended text every reader of it expects. */
        public readonly ?string $gradeLevel = null,
        /**
         * How the course is taught — one of {@see Course::types()}.
         *
         * ⚠️ NULLABLE HERE AND REFUSED IN THE ACTION, never defaulted. The column
         * has carried `recorded` by DB default since 2026-08-01 with no writer
         * anywhere, so every course a teacher ever made claimed to be recorded
         * about a classification nobody had made. A default in this DTO would be
         * that same unmade decision wearing a new face; the Action throws
         * instead, so the seeders and the panel meet the rule too.
         */
        public readonly ?string $courseType = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            description: $data['description'] ?? null,
            slug: $data['slug'] ?? null,
            priceMinor: (int) ($data['price_minor'] ?? 0),
            currency: $data['currency'] ?? 'QAR',
            status: $data['status'] ?? 'draft',
            visibility: $data['visibility'] ?? 'private',
            isSequential: $data['is_sequential'] ?? true,
            subjectUuid: isset($data['subject']) && is_string($data['subject']) ? $data['subject'] : null,
            gradeLevel: isset($data['grade_level']) && is_string($data['grade_level']) ? $data['grade_level'] : null,
            courseType: isset($data['course_type']) && is_string($data['course_type']) ? $data['course_type'] : null,
        );
    }
}
