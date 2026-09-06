<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Certificates\Actions\RegenerateCertificate;
use App\Modules\Certificates\Actions\ResolveCertificateDesign;
use App\Modules\Certificates\Http\Resources\CertificateResource;
use App\Modules\Certificates\Http\Resources\PublicCertificateResource;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    /**
     * Public, unauthenticated certificate verification by code.
     */
    public function verify(string $code, ResolveCertificateDesign $design): JsonResponse
    {
        $certificate = Certificate::withoutWorkspaceScope()
            ->where('verification_code', $code)
            ->first();

        /*
        | ⚠️ A REFUSAL CARRIES NOTHING — no name, no number, and NO `design`. The
        | design names an image the browser would then fetch, so a payload with one
        | turns a wrong code into a page that looks like a certificate whose
        | details failed to load. It has to look like a refusal.
        */
        if ($certificate === null) {
            return response()->json(['message' => 'Certificate not found.', 'valid' => false], 404);
        }

        return response()->json([
            'valid' => true,
            'certificate' => new PublicCertificateResource(
                $certificate->load('course'),
                $design->handle($certificate),
            ),
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

        /*
        | ⚠️ `->response()->getData(true)`, NEVER `response()->json(Resource::collection(…))`.
        | The second form never calls `toResponse()`, so `links` and `meta` are
        | dropped in SILENCE — `manage/certificates/page.tsx` read `meta.last_page`,
        | fell back to `1` for ever, and «عرض المزيد» never appeared for a teacher
        | with more than fifteen certificates. The screen's own comment warned about
        | exactly that (`FR-038` · `research.md` ق-١٣).
        |
        | ⚠️ AND THE SHAPE CHANGES: a bare array becomes `{data, links, meta}`. All
        | four callers were checked and every one reads `res.data`, which stays at
        | the top level; what is ADDED is `meta`. `CertificateListingTest` moved
        | from `0.…` to `data.0.…` in the same change.
        */
        return response()->json(CertificateResource::collection($certificates)->response()->getData(true));
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
