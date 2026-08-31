<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The four numbers the adaptive path is tuned by (FR-007).
 *
 * Rows in `platform_settings` with `config/assessments.php` as the fallback for a
 * database with nothing seeded — the precedent every operational number in this
 * product follows since 006.
 *
 * ⚠️ EVERY READ IS CLAMPED, AND THE CLAMP IS HERE RATHER THAN ON THE PANEL FIELD.
 * A setting is a free JSON value and the panel is not its only writer (a console
 * command, a seeder, a future import). `mastery_correct` is written into
 * `concept_masteries.threshold_correct`, an `unsignedTinyInt`: an operator typing
 * `300` produces a row strict MySQL REJECTS and SQLite silently truncates, so the
 * defect appears only in production and only for whoever mastered a concept that
 * day. A clamp at the reader is one place; a validation rule on a form is one
 * form.
 *
 * `start_difficulty` is clamped the same way for the same reason — a typo there
 * would otherwise reach `Difficulty::from()` and 500 every attempt to start.
 */
class AdaptiveSettings
{
    /** Two consecutive correct answers promote; one is noise, not evidence. */
    private const PROMOTE_MIN = 1;

    private const PROMOTE_MAX = 10;

    /** The column is `unsignedTinyInt`. See the clamp note above. */
    private const MASTERY_MIN = 1;

    private const MASTERY_MAX = 255;

    private const QUESTIONS_MIN = 1;

    /** `served_count` is `unsignedSmallInteger`, and nobody revises past this. */
    private const QUESTIONS_MAX = 200;

    public function promoteAfter(): int
    {
        return $this->clamp('promote_after', 2, self::PROMOTE_MIN, self::PROMOTE_MAX);
    }

    public function masteryCorrect(): int
    {
        return $this->clamp('mastery_correct', 3, self::MASTERY_MIN, self::MASTERY_MAX);
    }

    public function maxQuestions(): int
    {
        return $this->clamp('max_questions', 20, self::QUESTIONS_MIN, self::QUESTIONS_MAX);
    }

    public function startDifficulty(): Difficulty
    {
        $stored = PlatformSettings::get('assessments.adaptive.start_difficulty');

        // `tryFrom`, never `from`: an unreadable value is an operator's typo, and
        // the right answer to one is the documented default, not a 500 on every
        // student's first question.
        return (is_string($stored) ? Difficulty::tryFrom($stored) : null)
            ?? Difficulty::tryFrom((string) config('assessments.adaptive.start_difficulty', 'easy'))
            ?? Difficulty::Easy;
    }

    private function clamp(string $key, int $default, int $min, int $max): int
    {
        $stored = PlatformSettings::get(
            'assessments.adaptive.'.$key,
            config('assessments.adaptive.'.$key, $default),
        );

        return max($min, min($max, is_numeric($stored) ? (int) $stored : $default));
    }
}
