<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Http\Requests\StartImportRequest;
use App\Modules\Assessments\Http\Resources\ImportReportResource;
use App\Modules\Assessments\Jobs\ImportQuestionsJob;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionImport;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('create', Question::class);

        $imports = QuestionImport::query()->latest('id')->paginate(20);

        return response()->json([
            'data' => ImportReportResource::collection($imports->items()),
            'meta' => [
                'total' => $imports->total(),
                'current_page' => $imports->currentPage(),
                'last_page' => $imports->lastPage(),
            ],
        ]);
    }

    /**
     * Accepts the file and answers immediately.
     *
     * `202`, not `201`: nothing has been imported yet. A thousand rows is a
     * thousand inserts and holding the request open for them is how an upload
     * dies at a proxy timeout with half a bank written and no report (FR-008).
     */
    public function store(StartImportRequest $request): JsonResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return response()->json(['message' => 'اختر مساحة عمل قبل رفع ملف.'], 422);
        }

        $file = $request->file('file');

        $import = QuestionImport::create([
            'workspace_id' => $workspaceId,
            'uploaded_by' => $this->currentUser($request)->getKey(),
            'original_filename' => (string) $file->getClientOriginalName(),
            // Stored under the workspace so one teacher's uploads never share a
            // directory with another's, whatever the filenames collide on.
            'stored_path' => (string) $file->store('imports/'.$workspaceId),
            'duplicate_policy' => DuplicatePolicy::from($request->string('duplicate_policy')->toString()),
        ]);

        ImportQuestionsJob::dispatch((int) $import->getKey());

        return response()->json(['data' => ImportReportResource::make($import)], 202);
    }

    public function show(QuestionImport $import): JsonResponse
    {
        $this->authorize('create', Question::class);

        // The global scope answers the tenant question; this is the row-level
        // half, because a list filtered by a query and a record fetched by uuid
        // are two different questions.
        abort_if((int) $import->workspace_id !== app(WorkspaceContext::class)->id(), 404);

        return response()->json(['data' => ImportReportResource::make($import)]);
    }
}
