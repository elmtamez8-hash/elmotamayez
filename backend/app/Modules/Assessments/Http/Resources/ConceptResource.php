<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Concept;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Concept */
class ConceptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            // The bank filter shows an empty concept as worth removing; without
            // the count the teacher has to open each one to find out.
            'questions_count' => $this->whenCounted('questions'),
            // The one row a teacher did not create. The screen greys out its edit
            // control rather than letting the request come back 403.
            'is_default' => $this->name === Concept::UNCLASSIFIED,
        ];
    }
}
