<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPurchase;
use App\Shared\Contracts\OutstandingCreditsDirectory;

/**
 * القراءةُ الوحيدةُ لعدّادِ «حصص مدفوعة لم تُستهلَك».
 *
 * ⚠️ `withoutWorkspaceScope()` على الاثنَين: السائلُ موظَّفُ منصّة، والورشةُ
 * المسؤولُ عنها هي ورشةُ الطلبِ لا التي يصادفُ أنّه داخلٌ إليها.
 * `WorkspaceContext::id()` ترتدُّ إلى `users.last_workspace_id` له أيضاً، وهو
 * العطبُ الخماسيُّ الذي وجدَه ٠٢٤.
 *
 * ⚠️ وهي **قراءةٌ واحدةٌ** يقرؤُها البابانِ: نقطةُ النهايةِ
 * `OutstandingCreditsController` وشاشةُ اعتمادِ السعرِ في اللوحة. نسخُها في
 * الاثنَينِ يُنتِجُ رقمَينِ لسؤالٍ واحدٍ يتفارقانِ عندَ أوّلِ تعديل.
 */
class EloquentOutstandingCreditsDirectory implements OutstandingCreditsDirectory
{
    /**
     * @return array{sold: int, outstanding: int}
     */
    public function forWorkspace(int $workspaceId): array
    {
        return [
            'sold' => (int) CreditPurchase::query()
                ->withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->sum('credits'),
            'outstanding' => (int) CreditBalance::query()
                ->withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('remaining_credits', '>', 0)
                ->sum('remaining_credits'),
        ];
    }
}
