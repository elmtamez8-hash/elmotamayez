<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\SaveGradingScheme;
use App\Modules\Community\Data\GradingSchemeData;
use App\Modules\Community\Http\Requests\SaveGradingSchemeRequest;
use App\Modules\Community\Models\GradingScheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradingSchemeController extends Controller
{
    /** @return array<int, array<string, mixed>> */
    public function index(Request $request): array
    {
        abort_if($request->user()?->cannot('manage', GradingScheme::class) ?? true, 403);

        return GradingScheme::query()
            ->latest('period_end')
            ->get()
            ->map(fn (GradingScheme $scheme): array => [
                'uuid' => $scheme->uuid,
                'course_id' => $scheme->course_id,
                'period_start' => $scheme->period_start->toDateString(),
                'period_end' => $scheme->period_end->toDateString(),
                'weights' => $scheme->weights,
            ])
            ->all();
    }

    public function store(SaveGradingSchemeRequest $request, SaveGradingScheme $action): JsonResponse
    {
        $scheme = $action->handle(GradingSchemeData::fromArray($request->validated()));

        return response()->json([
            'uuid' => $scheme->uuid,
            'period_start' => $scheme->period_start->toDateString(),
            'period_end' => $scheme->period_end->toDateString(),
            'weights' => $scheme->weights,
        ], 201);
    }
}
