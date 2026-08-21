<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Shared\Actions\Action;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use DomainException;
use Illuminate\Support\Str;

/**
 * Open one request to see, export or erase a person's data (FR-015 · FR-019).
 *
 * ⚠️ ONE REFUSAL, WHICH DOES NOT DISTINGUISH "NO SUCH PERSON" FROM "NOT YOURS".
 * Two different answers would make this endpoint an ORACLE: submit a uuid, and a
 * distinct reply confirms that it belongs to a real account. `LinkGuardian` already
 * unifies the two deliberately — which is why the `exists` rule was dropped from
 * its request — and a second endpoint answering the same question two ways undoes
 * that at no benefit to anybody legitimate.
 *
 * ⚠️ AND THE PERMISSION IS `DataRights`, NEVER `RELATIONS_VIEW_STUDENT`. That one is
 * held by every teacher and every assistant in the product, and using it here would
 * make a cross-workspace export of a child's entire record a routine staff
 * capability.
 */
class CreateDataRequest extends Action
{
    public function __construct(private readonly GuardianDirectory $guardians) {}

    public function handle(User $requester, string $subjectUuid, DataRequestType $type): DataRequest
    {
        $subject = User::query()->where('uuid', $subjectUuid)->first();

        if ($subject === null) {
            $this->refuse();
        }

        $isSelf = (int) $subject->getKey() === (int) $requester->getKey();

        /*
        | The scope is FROZEN at creation, not read at execution. A guardian's
        | permissions can change while a request waits in a queue; re-reading them
        | at export time would mean the same request produces a different archive
        | depending on when the worker got to it, which is not a right anybody can
        | be told the shape of in advance.
        |
        | Null means the subject asked for their own data — see
        | {@see \App\Shared\Data\DataSubject::mayReceive()}. It is not an empty
        | list: an empty list is a guardian authorised for nothing.
        */
        $scope = null;

        if (! $isSelf) {
            $permissions = $this->guardians->permissionsFor($requester, $subject);

            if (! in_array(GuardianPermission::DataRights, $permissions, true)) {
                $this->refuse();
            }

            $scope = array_map(
                fn (GuardianPermission $permission): string => $permission->value,
                $permissions,
            );
        }

        return $this->open($requester, $subject, $type, $scope);
    }

    /**
     * @param  list<string>|null  $scope
     */
    private function open(User $requester, User $subject, DataRequestType $type, ?array $scope): DataRequest
    {
        $openKey = DataRequest::openKeyFor((int) $subject->getKey(), $type);
        $now = now();

        /*
        | ⚠️ `insertOrIgnore` WITH THE UUID AND THE TIMESTAMPS PASSED EXPLICITLY.
        | The unique index on `open_key` IS the concurrency guard — "read, then
        | insert" loses to two taps in one second, and a per-minute throttle does
        | not close a race measured in milliseconds. But `insertOrIgnore` writes a
        | row WITHOUT BOOTING THE MODEL, so `HasUuid` never fires: on MySQL the
        | resulting NOT NULL violation is downgraded to a warning, `''` is stored,
        | and every later request on the platform collides with that row on
        | `unique(uuid)` and is read as a duplicate for ever. The precedent and the
        | full account are on `CreditLedger::writeEntry()`.
        |
        | ⚠️ AND THE READ-BACK IS NOT OPTIONAL. Zero rows means EITHER a genuine
        | duplicate OR a swallowed failure, and the two are indistinguishable from
        | the return value — which is exactly why `create()` in a try/catch was
        | rejected: it makes a null, a foreign key and an out-of-range value look
        | like a second tap.
        */
        DataRequest::query()->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'subject_user_id' => $subject->getKey(),
            'requested_by_user_id' => $requester->getKey(),
            'type' => $type->value,
            'status' => DataRequestStatus::Pending->value,
            'open_key' => $openKey,
            'due_at' => $now->copy()->addDays(ComplianceSettings::requestDueDays()),
            'granted_scope' => $scope === null ? null : json_encode($scope),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $request = DataRequest::query()->where('open_key', $openKey)->first();

        if ($request === null) {
            throw new DomainException('تعذَّر فتحُ الطلب. حاوِلْ مرّةً أخرى.');
        }

        return $request;
    }

    private function refuse(): never
    {
        throw new DomainException('لا يمكن تقديمُ هذا الطلب لهذا الحساب.');
    }
}
