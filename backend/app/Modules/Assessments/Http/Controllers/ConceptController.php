<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Http\Requests\SaveConceptRequest;
use App\Modules\Assessments\Http\Resources\ConceptResource;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Marketplace\Models\Subject;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;

class ConceptController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Concept::class);

        // Counted in the query, not per row: the filter list is every concept the
        // teacher has, and `ConceptResource` would otherwise ask the database
        // once for each of them.
        $concepts = Concept::query()
            ->withCount('questions')
            ->orderBy('name')
            ->get();

        return response()->json(ConceptResource::collection($concepts));
    }

    public function store(SaveConceptRequest $request): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'اختر مساحة عمل قبل إضافة فكرة.'], 422);
        }

        $concept = Concept::create([
            'workspace_id' => $workspaceId,
            'name' => $request->string('name')->toString(),
            'subject_id' => $this->subjectId($request),
            'created_by' => $this->currentUser($request)->getKey(),
        ]);

        return response()->json(ConceptResource::make($concept), 201);
    }

    public function update(SaveConceptRequest $request, Concept $concept): JsonResponse
    {
        $concept->update([
            'name' => $request->string('name')->toString(),
            'subject_id' => $this->subjectId($request),
        ]);

        return response()->json(ConceptResource::make($concept));
    }

    private function subjectId(SaveConceptRequest $request): ?int
    {
        if (! $request->filled('subject_id')) {
            return null;
        }

        $id = Subject::query()->where('uuid', $request->string('subject_id')->toString())->value('id');

        return $id === null ? null : (int) $id;
    }
}
