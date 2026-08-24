<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Resources;

use App\Modules\Community\Models\ReportCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportCard
 */
class ReportCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'overall_pct' => $this->overall_pct,
            'improvement_index' => $this->improvement_index,
            'published_at' => $this->published_at?->toIso8601String(),
            // Whether the PDF has finished rendering. The card is published the
            // moment its totals are claimed and the file follows on a queue, so a
            // screen that offered the download unconditionally would send the
            // family to a 404 for the first few seconds of every card's life.
            'has_file' => $this->getFirstMedia('report_card_pdf') !== null,
            'segments' => ReportCardSegmentResource::collection($this->whenLoaded('segments')),
        ];
    }
}
