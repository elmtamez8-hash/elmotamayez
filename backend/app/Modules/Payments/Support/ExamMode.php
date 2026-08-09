<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\ExamModeWindow;

/**
 * Whether a workspace is inside an open exam window right now.
 *
 * One question, asked from three places — the floor, the withholding predicate
 * and the charge — so it is written once. {@see WithholdingReader} deliberately
 * does NOT use it: a panel asks about a whole list of workspaces and would turn
 * this into an N+1, which is why that class resolves them all in one query.
 *
 * The row is read without the workspace scope and filtered explicitly: the busy
 * caller is a queued listener, where the ambient workspace is null and a scoped
 * read would find nothing and quietly answer "no window open" for everyone.
 */
class ExamMode
{
    public function isOpen(int $workspaceId): bool
    {
        return ExamModeWindow::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->covering(now())
            ->exists();
    }
}
