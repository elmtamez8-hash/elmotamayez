<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Certificates\Actions\RegenerateCertificate;
use App\Modules\Certificates\Http\Resources\CertificateResource;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    /**
     * Public, unauthenticated certificate verification by code.
     */
    public function verify(string $code): JsonResponse
    {
        $certificate = Certificate::withoutWorkspaceScope()
            ->where('verification_code', $code)
            ->first();

        if ($certificate === null) {
            return response()->json(['message' => 'Certificate not found.', 'valid' => false], 404);
        }

        return response()->json([
            'valid' => true,
            'certificate' => CertificateResource::make($certificate->load(['course', 'student'])),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Certificate::query()
            ->with(['course', 'student'])
            /*
             | ⚠️ MATCHED THROUGH THE RELATION, SO AN UNKNOWN UUID MATCHES NOTHING
             | — the `ClassSessionController@index` idiom. FR-020's tab asks for
             | one course's certificate; a filter silently dropped would hand the
             | reader every certificate they hold under one course's heading.
             */
            ->when(
                $request->query('course'),
                fn ($q, $uuid) => $q->whereHas('course', fn ($course) => $course->where('uuid', $uuid)),
            );

        if (! $this->currentUser($request)->can(Permissions::CERTIFICATES_VIEW_ALL)) {
            $query->where('student_user_id', $this->currentUser($request)->getKey());
        }

        $certificates = $query->orderByDesc('issued_at')->paginate(15);

        return response()->json(CertificateResource::collection($certificates));
    }

    public function show(Certificate $certificate): JsonResponse
    {
        $this->authorize('view', $certificate);

        return response()->json(CertificateResource::make($certificate->load(['course', 'student'])));
    }

    public function regenerate(Certificate $certificate, RegenerateCertificate $action): JsonResponse
    {
        $this->authorize('regenerate', $certificate);

        return response()->json(CertificateResource::make($action->handle($certificate)->load(['course', 'student'])));
    }
}
