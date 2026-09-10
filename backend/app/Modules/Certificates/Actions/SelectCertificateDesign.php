<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Actions;

use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Makes one design THE design of a workspace — adopting a shipped template on the
 * way if that is what was named.
 *
 * ⚠️ ONE SPELLING FOR BOTH DOORS. `POST /certificate-designs` (adopt) and
 * `PATCH …{design} {is_selected: true}` (switch) are the same act with different
 * starting points, and two implementations of "make this the selected one" is two
 * places to forget to clear the previous row.
 */
class SelectCertificateDesign extends Action
{
    /**
     * @param  CertificateDesign|string  $target  a registry key to adopt, or an existing row to select
     */
    public function handle(int $workspaceId, CertificateDesign|string $target): CertificateDesign
    {
        return DB::transaction(function () use ($workspaceId, $target): CertificateDesign {
            $design = is_string($target)
                ? $this->adopt($workspaceId, $target)
                : $target;

            $this->guard($workspaceId, $design);

            /*
            | ⚠️ CLEAR THEN CLAIM, INSIDE ONE TRANSACTION. `selected_for_workspace_id`
            | is nullable and UNIQUE — never an `is_selected` boolean — because NULL
            | does not collide with NULL and MySQL has no partial indexes: `WHERE
            | is_selected = 1` on an index is a Postgres feature that silently does
            | not exist on the database this ships to. The `captured_order_id` idiom.
            |
            | Which also means the two statements cannot be reordered: claiming
            | before clearing raises the unique violation on the workspace's own
            | previous row.
            */
            CertificateDesign::withoutWorkspaceScope()
                ->where('selected_for_workspace_id', $workspaceId)
                ->update(['selected_for_workspace_id' => null]);

            /*
            | ⚠️ `forceFill`, because the column is deliberately not `$fillable`:
            | mass-assignable it becomes a second way to take this lock from outside
            | the transaction that owns it.
            */
            $design->forceFill(['selected_for_workspace_id' => $workspaceId])->save();

            return $design->refresh();
        });
    }

    /**
     * ⚠️ IDEMPOTENT ON PURPOSE — there is no unique index on
     * `(workspace_id, system_key)`. A teacher who adopts «كلاسيكي» twice (two taps,
     * a back button, a retried request) would otherwise own two rows for one
     * template, and the gallery merges the registry against the adopted rows BY
     * KEY — so the second row would appear as a duplicate «كلاسيكي» card whose
     * selection state disagrees with its twin.
     */
    private function adopt(int $workspaceId, string $key): CertificateDesign
    {
        if (CertificateTemplateRegistry::find($key) === null) {
            throw new DomainException('هذا القالب غير موجود.');
        }

        $existing = CertificateDesign::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('system_key', $key)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $design = new CertificateDesign(['system_key' => $key]);

        /*
        | The workspace is passed rather than left to `BelongsToWorkspace`: the
        | trait reads `WorkspaceContext`, and an Action must not depend on a
        | resolution a console command or a job would not have.
        |
        | `name` stays null for an adopted row — the display name is the registry's
        | (`name`), and a copy of it here is a second answer that goes stale the
        | day a template is renamed.
        */
        $design->forceFill(['workspace_id' => $workspaceId])->save();

        return $design;
    }

    private function guard(int $workspaceId, CertificateDesign $design): void
    {
        if ((int) $design->workspace_id !== $workspaceId) {
            throw new DomainException('هذا التصميم لا يخصّ مساحة عملك.');
        }

        /*
        | ⚠️ «EXACTLY ONE OF THE TWO COLUMNS», ENFORCED HERE AND NOT ONLY IN A
        | FORM REQUEST (المبدأ الثاني). A seeder and the Filament panel reach this
        | model with no form behind them, and a row carrying both would draw an
        | uploaded image with a shipped template's boxes measured for another one.
        */
        $hasKey = $design->system_key !== null;
        $hasFile = $design->image_path !== null;

        if ($hasKey === $hasFile) {
            throw new DomainException('التصميم يحمل مصدرين أو لا يحمل أيّاً منهما.');
        }

        /*
        | ⚠️ AN UNREADY DESIGN IS REFUSED WITH A REASON, NEVER SILENTLY (`FR-044`).
        | An uploaded image with no field positions prints the student's name over
        | the artwork's ornament — and the first person to see that is the student,
        | on their own certificate.
        */
        if (! $design->isReady()) {
            throw new DomainException('اضبط مواضع الحقول على هذا التصميم قبل اعتماده.');
        }
    }
}
