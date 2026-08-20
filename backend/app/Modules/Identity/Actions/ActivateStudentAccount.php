<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Support\UserStatus;
use App\Shared\Actions\Action;
use App\Shared\Contracts\ConsentDirectory;
use DomainException;

/**
 * A minor's account becomes usable, and not one moment earlier (FR-003 · SC-001).
 *
 * ⚠️ IT LIVES IN `Identity`, NOT IN `Compliance`, and that placement is the whole
 * of the module boundary here. An Action in the compliance module writing
 * `users.status` is precisely the coupling the `PersonalDataOwner` contract exists
 * to avoid. Compliance owns the catalogue, the requests, the holds and the sweep —
 * it does NOT own the account lifecycle. It asks `ConsentDirectory` and fires an
 * event; this class writes.
 *
 * ⚠️ AND THE CONSENT IS RE-READ HERE RATHER THAN TRUSTED FROM THE CALLER. The
 * listener that calls this fires from a consent being recorded, so "obviously"
 * the consent exists — but this is also the entrance a seeder, a panel action or
 * a future endpoint uses, and Constitution II says the rule belongs at the single
 * entrance rather than at each door leading to it.
 */
class ActivateStudentAccount extends Action
{
    /** The document a minor's guardian must have granted. */
    public const DOCUMENT = 'data_processing';

    public function __construct(private readonly ConsentDirectory $consent) {}

    /**
     * @return bool whether this call changed anything
     */
    public function handle(User $student): bool
    {
        if ($student->status === UserStatus::Active->value) {
            /*
            | Already active, and this is a SUCCESS rather than an error. The
            | listener runs on every recorded consent, and a guardian signing a
            | republished version of the terms for a student who has been studying
            | for a year must not raise anything.
            */
            return false;
        }

        if (! $this->consent->hasCurrent($student, self::DOCUMENT)) {
            /*
            | ⚠️ THIS IS ALSO WHERE "REFUSAL WINS" TAKES EFFECT (R6). `hasCurrent()`
            | answers false when an authorised guardian refused more recently than
            | any grant — so a second guardian's refusal keeps the account closed
            | even though a first guardian's consent row is sitting in the table.
            */
            throw new DomainException('لا يُفعَّل الحساب قبل موافقةٍ سارية من وليّ الأمر على معالجة البيانات.');
        }

        /*
        | ⚠️ A CONDITIONAL UPDATE, not a read followed by a save. Two guardians
        | consenting within the same second is an ordinary event in a family, and
        | with a plain save both would pass the check above and both would write —
        | firing whatever listens to activation twice.
        */
        $changed = User::query()
            ->whereKey($student->getKey())
            ->where('status', UserStatus::PendingGuardianConsent->value)
            ->update(['status' => UserStatus::Active->value]);

        if ($changed === 0) {
            return false;
        }

        $student->refresh();

        return true;
    }
}
