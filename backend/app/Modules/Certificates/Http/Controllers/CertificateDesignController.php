<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Certificates\Actions\DeleteCertificateDesign;
use App\Modules\Certificates\Actions\SaveFieldBoxes;
use App\Modules\Certificates\Actions\SelectCertificateDesign;
use App\Modules\Certificates\Actions\UploadCertificateDesign;
use App\Modules\Certificates\Http\Requests\SaveBoxesRequest;
use App\Modules\Certificates\Http\Requests\StoreDesignRequest;
use App\Modules\Certificates\Http\Resources\CertificateDesignResource;
use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Certificates\Support\CertificateDesignLimits;
use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class CertificateDesignController extends Controller
{
    /**
     * The gallery: every shipped template, plus this workspace's own uploads.
     *
     * ⚠️ THE REGISTRY IS MERGED AGAINST THE ADOPTED ROWS BY KEY, so a template
     * appears exactly once whether or not this workspace has adopted it. Listing
     * the rows alone would show a teacher who has adopted nothing an EMPTY gallery
     * over a product that ships two designs.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CertificateDesign::class);

        $rows = CertificateDesign::query()->orderBy('id')->get();
        $adopted = $rows->whereNotNull('system_key')->keyBy('system_key');
        $uploaded = $rows->whereNull('system_key');

        $data = [];

        foreach (CertificateTemplateRegistry::all() as $template) {
            $row = $adopted->get($template['key']);

            $data[] = $row instanceof CertificateDesign
                ? CertificateDesignResource::make($row)->resolve($request)
                : CertificateDesignResource::fromTemplate($template);
        }

        foreach ($uploaded as $row) {
            $data[] = CertificateDesignResource::make($row)->resolve($request);
        }

        return response()->json([
            'data' => $data,
            // ⚠️ The announced limit and the enforced one are one read.
            'upload_limit' => CertificateDesignLimits::uploadLimit(),
            'uploads_used' => $uploaded->count(),
        ]);
    }

    /**
     * Adopt a shipped template, or upload one of your own.
     *
     * ⚠️ THE TWO BRANCHES END DIFFERENTLY ON PURPOSE. Adopting SELECTS in the same
     * act — a teacher who picks a design from a gallery has said what they want,
     * and a second «اعتمد» press exists only because the row and the selection are
     * two writes on our side. An upload does NOT: it arrives with no field
     * positions, and selecting it would print the student's name over the
     * artwork's ornament (`FR-044`).
     */
    public function store(
        StoreDesignRequest $request,
        SelectCertificateDesign $select,
        UploadCertificateDesign $upload,
    ): JsonResponse {
        $this->authorize('create', CertificateDesign::class);

        $file = $request->file('image');

        $design = $file instanceof UploadedFile
            ? $upload->handle(
                $this->workspaceId(),
                $file,
                (string) ($request->validated('name') ?? 'تصميمي'),
            )
            : $select->handle($this->workspaceId(), (string) $request->validated('system_key'));

        return response()->json(CertificateDesignResource::make($design), 201);
    }

    /**
     * ⚠️ The workspace falls back to the shipped default if this was the selected
     * design — no certificate breaks, because none of them stores a design at all.
     */
    public function destroy(CertificateDesign $design, DeleteCertificateDesign $delete): JsonResponse
    {
        $this->authorize('delete', $design);

        $delete->handle($design);

        return response()->json(null, 204);
    }

    /**
     * Field positions, selection, or both.
     *
     * ⚠️ `has('boxes')` AND NOT `filled()`. `boxes: null` is a REQUEST — «go back
     * to the template's own positions» (`FR-021`) — and `filled()` reads it as
     * absent, so the reset button would answer 200 and change nothing.
     *
     * ⚠️ And only `is_selected: true` acts. There is no «deselect»: a workspace
     * always draws on something, and clearing the selection would silently move
     * every certificate to the default. Falling back is what DELETE is for.
     */
    public function update(
        SaveBoxesRequest $request,
        CertificateDesign $design,
        SaveFieldBoxes $saveBoxes,
        SelectCertificateDesign $select,
    ): JsonResponse {
        $this->authorize('update', $design);

        if ($request->has('boxes')) {
            /** @var array<mixed>|null $boxes */
            $boxes = $request->input('boxes');

            $design = $saveBoxes->handle($design, $boxes);
        }

        if ($request->boolean('is_selected')) {
            $design = $select->handle($this->workspaceId(), $design);
        }

        return response()->json(CertificateDesignResource::make($design));
    }

    private function workspaceId(): int
    {
        // The route is inside `auth:sanctum` behind `EnsureCurrentWorkspace`, so a
        // null here would mean a member with no workspace at all.
        return (int) app(WorkspaceContext::class)->id();
    }
}
