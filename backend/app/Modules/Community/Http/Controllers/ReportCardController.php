<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Http\Resources\ReportCardResource;
use App\Modules\Community\Models\ReportCard;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The student's and the guardian's view of the cumulative card (FR-039, FR-040).
 *
 * ⚠️ EVERY QUERY HERE CARRIES AN EXPLICIT `student_user_id` FILTER, and nothing
 * relies on a scope. `report_cards` is platform-owned with no `workspace_id`, and
 * a student is a member of no workspace at all — so `WorkspaceScope` adds no
 * condition on any route reachable from here. The filter IS the isolation.
 */
class ReportCardController extends Controller
{
    public function __construct(private readonly GuardianDirectory $guardians) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $studentId = $this->subjectId($request);

        $cards = ReportCard::query()
            // ⚠️ `media` EAGER-LOADED. `ReportCardResource` asks `getFirstMedia()`
            // for `has_file`, and a Resource runs once per row — so without this
            // the list costs one `media` SELECT per card, which is the N+1
            // `ClassSessionResource` already cost this repository once.
            ->with('media')
            ->where('student_user_id', $studentId)
            // A card that has not been published has no totals and no file: it is
            // a half-built row, not a document. Nothing outside the build job ever
            // sees one.
            ->whereNotNull('published_at')
            ->latest('period_end')
            ->get();

        return ReportCardResource::collection($cards);
    }

    public function show(Request $request, string $uuid): ReportCardResource
    {
        $card = $this->findForReader($request, $uuid);

        $card->load(['media', 'segments.teacher:id,first_name,last_name']);

        return new ReportCardResource($card);
    }

    /**
     * Mint a five-minute signed URL for the file (FR-039).
     *
     * ⚠️ A SHORT-LIVED SIGNED URL, NEVER THE FILE ITSELF AND NEVER A PERMANENT
     * PATH. The document carries a named minor's grades, so a URL that outlived
     * the request would be a permanently linkable copy of it — the reasoning that
     * keeps caption files behind a grant rather than on a public path.
     *
     * ⚠️ AND IT IS RETURNED AS JSON RATHER THAN AS A `302`, because a redirect
     * here would be a redirect nobody can follow. The frontend keeps its Sanctum
     * token in `localStorage`, and a plain `<a href>` — or a print dialog, or a
     * new tab — sends no `Authorization` header at all, so the browser would be
     * answered `401` before it ever saw the redirect. The same fact put captions
     * behind a grant URL in 019 and chat attachments behind a signed one in this
     * spec's own US2. The client fetches this with its token and then navigates.
     *
     * This is where `throttle:report-card-render` is spent, on the one call that
     * has an account behind it.
     *
     * @return array{url: string, expires_in: int}
     */
    public function download(Request $request, string $uuid): array
    {
        $card = $this->findForReader($request, $uuid);

        abort_if($card->getFirstMedia('report_card_pdf') === null, 404);

        return [
            'url' => URL::temporarySignedRoute(
                'report-cards.file',
                now()->addMinutes(5),
                // ⚠️ BOUND TO THE READER AS WELL AS THE CARD (T149). The file
                // route is unauthenticated by necessity, so the signature is the
                // only thing tying the link to the person it was minted for — a
                // forwarded URL is then a signature over somebody else's id.
                ['uuid' => $card->uuid, 'reader' => (int) $request->user()?->getKey()],
            ),
            'expires_in' => 300,
        ];
    }

    /** The signed target of the URL minted above. */
    public function file(Request $request, string $uuid): StreamedResponse
    {
        // The signature already proves the reader was authorised when the link
        // was minted; re-deriving the permission here would be a second spelling
        // of one question, and the link is five minutes old at most.
        $card = ReportCard::query()->where('uuid', $uuid)->firstOrFail();

        $media = $card->getFirstMedia('report_card_pdf');

        abort_if($media === null, 404);

        return $media->toResponse($request);
    }

    /** Which student this request is about — the reader, or a child they hold. */
    private function subjectId(Request $request): int
    {
        $user = $request->user();
        $requested = $request->query('student');

        // Never reachable behind `auth:sanctum`, but the type says otherwise and
        // a null here would ask the directory about nobody and answer with
        // somebody else's child.
        abort_if($user === null, 401);

        if ($requested === null) {
            return (int) $user->getKey();
        }

        $child = $this->guardians
            ->childrenOf($user, GuardianPermission::Results)
            ->firstWhere('uuid', (string) $requested);

        // 403, not 404: the uuid either belongs to a child this guardian holds or
        // it does not, and answering "no such card" for someone else's child is
        // an identity probe wearing a friendlier status code.
        abort_if($child === null, 403);

        return (int) $child->getKey();
    }

    private function findForReader(Request $request, string $uuid): ReportCard
    {
        $card = ReportCard::query()
            ->where('uuid', $uuid)
            ->whereNotNull('published_at')
            ->firstOrFail();

        abort_if($request->user()?->cannot('view', $card) ?? true, 403);

        return $card;
    }
}
