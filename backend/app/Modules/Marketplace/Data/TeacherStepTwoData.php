<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Data;

use App\Shared\Data\DataTransferObject;

/** Step 2 — what they teach. Taxonomy travels as slugs, never ids. */
class TeacherStepTwoData extends DataTransferObject
{
    /**
     * @param  list<string>  $subjects
     * @param  list<string>  $gradeLevels
     * @param  list<string>  $qualifications
     * @param  list<string>  $teachingLanguages
     */
    public function __construct(
        public readonly array $subjects,
        public readonly array $gradeLevels,
        public readonly int $yearsExperience,
        public readonly array $qualifications,
        public readonly array $teachingLanguages,
        public readonly string $headline,
        public readonly ?string $bio,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            subjects: array_values(array_map(strval(...), (array) $data['subjects'])),
            gradeLevels: array_values(array_map(strval(...), (array) $data['grade_levels'])),
            yearsExperience: (int) $data['years_experience'],
            qualifications: array_values(array_map(strval(...), (array) ($data['qualifications'] ?? []))),
            teachingLanguages: array_values(array_map(strval(...), (array) $data['teaching_languages'])),
            headline: (string) $data['headline'],
            bio: isset($data['bio']) ? (string) $data['bio'] : null,
        );
    }

    /**
     * Snake_case shape for step_data.
     *
     * Not toArray(): that returns the promoted property names, and a round trip
     * through it would hand fromArray() `gradeLevels` when it reads `grade_levels`.
     *
     * @return array<string, mixed>
     */
    public function toStepData(): array
    {
        return [
            'subjects' => $this->subjects,
            'grade_levels' => $this->gradeLevels,
            'years_experience' => $this->yearsExperience,
            'qualifications' => $this->qualifications,
            'teaching_languages' => $this->teachingLanguages,
            'headline' => $this->headline,
            'bio' => $this->bio,
        ];
    }
}
