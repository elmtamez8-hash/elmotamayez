<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generated paper as the student must see it — before they answer it.
 *
 * ⚠️ IT IS BUILT FROM THE FROZEN SNAPSHOT, AND `correct_option_ids` IS THE ONE
 * KEY IT DOES NOT COPY OUT. The snapshot exists so grading compares against what
 * was actually shown; handing the whole of it to the browser would publish the
 * answer key of every question on the page, inside the response that opens the
 * page. The explanations are in there too — they are shown afterwards, by
 * {@see PracticeResultResource}, and not one moment earlier.
 *
 * @mixin Attempt
 */
class PracticeAttemptResource extends JsonResource
{
    public function __construct(
        Attempt $attempt,
        private readonly int $requested = 0,
        private readonly int $delivered = 0,
        private readonly int $durationMinutes = 0,
    ) {
        parent::__construct($attempt);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $questions = [];

        foreach ($this->items()->orderBy('order')->get() as $item) {
            $questions[] = $this->question($item);
        }

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'questions' => $questions,
            /*
             | ⚠️ BOTH NUMBERS TRAVEL, and the pair is FR-023 itself. A paper of
             | four for a request of ten is a correct answer — the bank held no
             | more the student was entitled to — but only if the screen can say
             | so. Sent alone, `delivered` is a number nobody can question.
             */
            'requested_count' => $this->requested === 0 ? count($questions) : $this->requested,
            'delivered_count' => $this->delivered === 0 ? count($questions) : $this->delivered,
            // The client's clock. Not enforced here: see SelfExamCriteria.
            'duration_minutes' => $this->durationMinutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function question(AttemptItem $item): array
    {
        $snapshot = $item->snapshot;

        return [
            'id' => (int) $item->question_id,
            'type' => $snapshot['type'] ?? 'mcq',
            'content' => $snapshot['content'] ?? '',
            'points' => (int) $item->points,
            'options' => array_map(
                static fn (array $option): array => [
                    'id' => (int) $option['id'],
                    'content' => (string) $option['content'],
                ],
                is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [],
            ),
        ];
    }
}
