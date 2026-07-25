<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Certificates\Http\Requests\StoreTemplateRequest;
use App\Modules\Certificates\Http\Requests\UpdateTemplateRequest;
use App\Modules\Certificates\Http\Resources\CertificateTemplateResource;
use App\Modules\Certificates\Models\CertificateTemplate;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;

class CertificateTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize(Permissions::CERTIFICATES_REGENERATE);

        $templates = CertificateTemplate::query()
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json(CertificateTemplateResource::collection($templates));
    }

    public function show(CertificateTemplate $template): JsonResponse
    {
        $this->authorize(Permissions::CERTIFICATES_REGENERATE);

        return response()->json(CertificateTemplateResource::make($template));
    }

    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $template = CertificateTemplate::create(array_merge($request->validated(), [
            'workspace_id' => app(WorkspaceContext::class)->id(),
        ]));

        return response()->json(CertificateTemplateResource::make($template), 201);
    }

    public function update(UpdateTemplateRequest $request, CertificateTemplate $template): JsonResponse
    {
        $this->authorize(Permissions::CERTIFICATES_REGENERATE);

        $template->update($request->validated());

        return response()->json(CertificateTemplateResource::make($template->fresh()));
    }

    public function destroy(CertificateTemplate $template): JsonResponse
    {
        $this->authorize(Permissions::CERTIFICATES_REGENERATE);

        $template->delete();

        return response()->json(null, 204);
    }
}
