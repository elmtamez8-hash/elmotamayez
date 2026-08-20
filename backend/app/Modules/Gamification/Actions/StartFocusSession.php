<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Models\User;
use App\Modules\Gamification\Enums\FocusSessionStatus;
use App\Modules\Gamification\Models\FocusSession;
use App\Modules\Gamification\Support\GamificationSettings;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Begin a study session (FR-038).
 *
 * ⚠️ THE DURATION IS BOUNDED HERE AS WELL AS IN THE FormRequest. Unbounded, a
 * `minutes` of 100000 mutes every optional notification for eleven weeks — and
 * the FormRequest is only the door the browser uses.
 *
 * ⚠️ AND A SECOND START CLOSES THE FIRST. Two running sessions for one person
 * would make "is this student focusing?" ambiguous and leave an orphan that never
 * ends — the mute stuck on with nothing to switch it off.
 */
class StartFocusSession extends Action
{
    public function __construct(private readonly GamificationSettings $settings) {}

    public function handle(User $student, int $minutes): FocusSession
    {
        $min = $this->settings->focusMinMinutes();
        $max = $this->settings->focusMaxMinutes();

        if ($minutes < $min || $minutes > $max) {
            throw new DomainException("مدة الجلسة يجب أن تكون بين {$min} و{$max} دقيقة.");
        }

        FocusSession::query()
            ->where('user_id', $student->getKey())
            ->where('status', FocusSessionStatus::Running->value)
            ->update([
                'status' => FocusSessionStatus::Interrupted->value,
                'ended_at' => now(),
                'updated_at' => now(),
            ]);

        return FocusSession::query()->create([
            'user_id' => $student->getKey(),
            'planned_minutes' => $minutes,
            'started_at' => now(),
            'status' => FocusSessionStatus::Running,
        ]);
    }
}
