<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Support\AdaptiveSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The state of one adaptive session, as the screen must draw it.
 *
 * ⚠️ `ceiling_difficulty` AND `mastery_after` TRAVEL, AND THEY ARE THE FEATURE
 * RATHER THAN DECORATION (FR-003). The student is being asked to reach a bar, and
 * a bar nobody states is a bar nobody can aim at — worse, a screen that DERIVED
 * the ceiling as «hard» would ask a student practising an all-easy concept for
 * something no action of theirs can produce. The server computed it from that
 * concept's own bank; the client reads it and does not recompute it.
 *
 * @mixin AdaptiveSession
 */
class AdaptiveSessionResource extends JsonResource
{
    public function __construct(AdaptiveSession $session, private readonly ?AdaptiveSettings $settings = null)
    {
        parent::__construct($session);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $settings = $this->settings ?? app(AdaptiveSettings::class);

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'difficulty' => $this->current_difficulty->value,
            'ceiling_difficulty' => $this->ceiling_difficulty->value,
            'correct_streak' => (int) $this->correct_streak,
            'served_count' => (int) $this->served_count,
            'max_questions' => $settings->maxQuestions(),
            'mastery_after' => $settings->masteryCorrect(),
            'concept' => [
                'uuid' => (string) $this->concept?->uuid,
                'name' => (string) $this->concept?->name,
            ],
            'mastered_at' => $this->mastered_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            // Only once the paper is sealed: a running session has no score to
            // report, and a partial percentage read as a result is a lie.
            'score' => $this->status->isOpen() ? null : (float) $this->attempt?->score,
        ];
    }
}
