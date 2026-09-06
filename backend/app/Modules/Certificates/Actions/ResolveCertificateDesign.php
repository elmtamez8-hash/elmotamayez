<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Actions;

use App\Modules\Certificates\Models\Certificate;
use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use App\Shared\Actions\Action;

/**
 * Which artwork a given certificate is drawn on, and where its six fields sit.
 *
 * ⚠️ READ LIVE, NOT FROZEN — and that is the opposite of the six FACTS, on
 * purpose. The name, subject, teacher, date, number and code are frozen at issue
 * because they state what happened. The PRESENTATION is read at every request so
 * that a crooked box, corrected once, is corrected on every certificate ever
 * issued — including the ones already printed and hanging on a wall.
 *
 * ⚠️ AND IT IS THE ONLY SPELLING OF THIS RESOLUTION. A controller or a resource
 * deriving it a second time is how a gallery preview stops matching the
 * certificate it previews, which is the worst thing a position editor can do.
 */
class ResolveCertificateDesign extends Action
{
    /** @return array{image_url: string, boxes: array<string, array<string, mixed>>} */
    public function handle(Certificate $certificate): array
    {
        $design = $this->selectedFor((int) $certificate->workspace_id);
        $default = CertificateTemplateRegistry::default();

        /*
        | ⚠️ THE DERIVATION LIVES ON THE MODEL, and this Action and the gallery
        | Resource both call it. Spelling it a second time in either place is how
        | the preview a teacher adjusted stops matching the certificate a parent
        | opens.
        */
        return [
            'image_url' => $design?->imageUrl() ?? $default['image_url'],
            'boxes' => $design?->effectiveBoxes() ?? $default['boxes'],
        ];
    }

    /*
    | ⚠️ THE SCOPE IS BYPASSED, AND THE VICTIM IS NOT THE VISITOR.
    |
    | `/certificates/verify/{code}` is public. `WorkspaceScope::apply()` adds no
    | condition at all when the context resolves to null, which it always does for
    | a guest — so a guest reads this correctly whether the bypass is here or not,
    | and a guest-only test passes against a build with no bypass in it.
    |
    | The person it breaks is a SIGNED-IN TEACHER FROM ANOTHER WORKSPACE opening
    | the link: their context resolves to their own workspace, the scope bites,
    | and they are shown their own design over somebody else's certificate — or
    | none at all. Family of the five-layer defect spec 024 found in the payments
    | approval chain, where the same fallback (`users.last_workspace_id`) made a
    | platform-wide read answer about one workspace.
    |
    | So: bypass the scope, and put the workspace back BY HAND from the row we are
    | drawing. `DesignScopeBypassTest` needs two workspaces and a foreign teacher
    | who is logged in — without both, it proves nothing.
    */
    private function selectedFor(int $workspaceId): ?CertificateDesign
    {
        return CertificateDesign::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('selected_for_workspace_id', $workspaceId)
            ->first();
    }
}
