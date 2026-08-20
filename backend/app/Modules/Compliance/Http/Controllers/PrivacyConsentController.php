<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compliance\Http\Requests\UpdateConsentCategoriesRequest;
use App\Modules\Compliance\Models\DataCategory;
use App\Shared\Contracts\ConsentDirectory;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Changing which optional categories a person consents to (FR-007).
 *
 * ⚠️ `FR-007` HAD NO TABLE, NO COLUMN, NO ACTION, NO ROUTE AND NO TEST until this
 * phase. Three planning documents recorded `FR-005` as "implemented literally",
 * which was wrong — the requirement names five things and the fifth is WHICH
 * CATEGORIES, and there was no column of any kind for it. The withdrawal right
 * that depends on it therefore had nothing behind it at all.
 *
 * ⚠️ THE COMPLETE SET IS SENT, NEVER A DIFF. A diff applied to state that was read
 * a second ago is the lost update — and here the lost update decides whether a
 * child's data may be processed. Same shape, and the same reason, as spec 016's
 * reorder sending the whole sibling list.
 *
 * ⚠️ AND NO ROW IS EVER EDITED. A withdrawal writes a NEW consent row carrying a
 * narrower set; the newest row is the one in force. Editing the old one would
 * erase the evidence of what was actually agreed — the argument `LedgerEntry` and
 * `CreditTransaction` already make for themselves.
 */
class PrivacyConsentController extends Controller
{
    /** The document these categories belong to. */
    private const DOCUMENT = 'data_processing';

    public function __construct(
        private readonly ConsentDirectory $consent,
        private readonly GuardianDirectory $guardians,
    ) {}

    public function update(UpdateConsentCategoriesRequest $request): JsonResponse
    {
        $signer = $this->currentUser($request);
        $subject = $request->subject($signer);

        if ($subject === null || ! $this->maySignFor($signer, $subject)) {
            /*
            | ⚠️ ONE REFUSAL FOR TWO DIFFERENT FACTS. "No such student" and "not
            | yours" answer identically — `LinkGuardian` already unifies them
            | deliberately, and two distinct codes would turn this into an oracle
            | confirming that a submitted identifier belongs to a real person.
            */
            throw new HttpException(403, 'لا يحقّ لك تعديل موافقة هذا الطالب.');
        }

        /*
        | ⚠️ THE VERSION THE CLIENT READ IS CHECKED AGAINST THE ONE IN FORCE. A
        | consent names the version it was given for; accepting a submission
        | against superseded text would record a signature on words the person
        | never saw. Answering 409 rather than 422 because nothing about the
        | request is malformed — the text simply moved underneath it.
        */
        $current = $this->consent->currentVersion(self::DOCUMENT);

        if ($request->string('version')->toString() !== $current) {
            return response()->json([
                'message' => 'تغيّر نصُّ السياسة. أعِدْ قراءتَه ثمّ أكّد اختيارك.',
                'code' => 'policy_version_changed',
                'version' => $current,
            ], 409);
        }

        /*
        | ⚠️ THE REQUIRED CATEGORIES ARE ADDED BACK, NOT VALIDATED AWAY. A client
        | that omits one is not refused — a required category cannot be withdrawn,
        | so the correct answer is that the stored set contains it regardless.
        | Rejecting instead would let a stale screen block a legitimate withdrawal
        | of something else.
        */
        $required = DataCategory::query()->where('is_required', true)->pluck('key')->all();
        $chosen = array_values(array_unique([...$request->categories(), ...$required]));

        $this->consent->record(
            $signer,
            $subject,
            self::DOCUMENT,
            $chosen,
            (string) $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'version' => $current,
            'categories' => $chosen,
        ]);
    }

    private function maySignFor(User $signer, User $subject): bool
    {
        if ($signer->getKey() === $subject->getKey()) {
            return true;
        }

        // Asked through the directory rather than by querying the relations table
        // — and with `DataRights`, the permission that exists for exactly this.
        return $this->guardians->isAuthorised($signer, $subject, GuardianPermission::DataRights);
    }
}
