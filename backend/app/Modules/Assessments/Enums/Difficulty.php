<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

enum Difficulty: string implements HasArabicLabel
{
    use BuildsOptions;

    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    public function label(): string
    {
        return match ($this) {
            self::Easy => 'سهل',
            self::Medium => 'متوسّط',
            self::Hard => 'صعب',
        };
    }

    /**
     * Where this sits on the ladder (spec 012 · FR-002).
     *
     * The ordering is a property of the enum, not a branch in whoever needs it —
     * the precedent is `NotificationChannel::isExternal()`. Written as a `match`
     * in one place, adding a fourth difficulty is one file; written as
     * comparisons at the call sites it is however many the grep finds.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Easy => 1,
            self::Medium => 2,
            self::Hard => 3,
        };
    }

    /**
     * One step down, or null at the bottom.
     *
     * ⚠️ NULL AND NOT `self`. "There is no step below easy" is a fact the caller
     * has to act on — the adaptive session stays where it is and says so. A
     * self-returning form makes a demotion that did not happen read exactly like
     * one that did, at every call site, for ever.
     */
    public function easier(): ?self
    {
        return match ($this) {
            self::Easy => null,
            self::Medium => self::Easy,
            self::Hard => self::Medium,
        };
    }

    /** One step up, or null at the top. Null for the same reason as {@see easier()}. */
    public function harder(): ?self
    {
        return match ($this) {
            self::Easy => self::Medium,
            self::Medium => self::Hard,
            self::Hard => null,
        };
    }
}
