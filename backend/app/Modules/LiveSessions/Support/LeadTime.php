<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Shared\Support\CountedNoun;
use Carbon\CarbonImmutable;

/**
 * How far ahead a student must ask for a lesson.
 *
 * ⛔ «NOT IN THE PAST» WAS THE WHOLE RULE, AND IT LET A PRIVATE HOUR BE ASKED
 * FOR ONE MINUTE FROM NOW. A request nobody can read, accept and prepare for is
 * a request that expires unanswered — or worse, is accepted by a teacher who
 * then misses it. The number is `sessions.min_lead_minutes`, a row an operator
 * tunes; this class is the one place both doors ask it, so a private request
 * and a proposed reschedule can never disagree about «too soon».
 *
 * ⚠️ THE COURSE PAGE READS THE SAME NUMBER. `PublicMarketplaceController` sends
 * it beside the availability, and the slot picker starts counting from it —
 * otherwise the screen offers exactly the hours this refuses.
 */
final class LeadTime
{
    public function __construct(private readonly SessionSettings $settings) {}

    public function minutes(): int
    {
        return $this->settings->minLeadMinutes();
    }

    /** The first moment a lesson may be asked for. */
    public function earliest(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes($this->minutes());
    }

    /**
     * Null when `$at` is far enough ahead; otherwise the sentence to show.
     *
     * The past keeps its own sentence at each caller — «already gone» and «too
     * soon» ask the student for different things.
     */
    public function refusalFor(CarbonImmutable $at): ?string
    {
        if ($at->greaterThanOrEqualTo($this->earliest())) {
            return null;
        }

        return 'اختر موعداً يبدأ بعد '.$this->describe().' من الآن على الأقل، ليتّسع للمدرّس أن يردّ ويستعدّ.';
    }

    private function describe(): string
    {
        $minutes = $this->minutes();

        if ($minutes % 60 === 0) {
            return CountedNoun::of(intdiv($minutes, 60), [
                'one' => 'ساعة',
                'two' => 'ساعتين',
                'few' => 'ساعات',
                'many' => 'ساعة',
                'other' => 'ساعة',
            ]);
        }

        return CountedNoun::of($minutes, [
            'one' => 'دقيقة',
            'two' => 'دقيقتين',
            'few' => 'دقائق',
            'many' => 'دقيقة',
            'other' => 'دقيقة',
        ]);
    }
}
