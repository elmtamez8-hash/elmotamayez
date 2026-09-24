<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Payments\Actions\RecordTermsConsent;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Http\Requests\StoreTermsConsentRequest;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * What this person has been asked to accept, and the acceptance itself.
 *
 * ⚠️ EVERY REFUSAL IS THE SAME 403 — a student uuid that matches nobody, one that
 * matches somebody who is not this signer's child, and a guardian relation
 * without the payments permission. Distinguishing them would turn the endpoint
 * into a directory: post a uuid, read the status, learn whether the account
 * exists. The same shape as `Manage\CreditLimitController`, for the same reason.
 *
 * The GET is not in the task list and is here for the reason the exam-mode GET
 * is: a screen cannot ask for an acceptance it has no way to know is outstanding,
 * and the version it would have to hard-code is the one thing that changes.
 */
class TermsConsentController extends Controller
{
    public function index(Request $request, ConsentRegistry $registry, GuardianDirectory $guardians): JsonResponse
    {
        $reader = $this->currentUser($request);

        if ($request->string('student')->toString() === '') {
            return response()->json(['data' => $this->stateFor($reader, $registry)]);
        }

        /*
        | ⚠️ A GUARDIAN READING FOR A CHILD SEES THE DEFERRED-PAYMENT TERMS ALONE.
        | The read is resolved through the same `studentFor()` the signature uses,
        | with the same permission (`Payments`) — two spellings of «who may see
        | this child's outstanding terms» would put one answer on the card and
        | another at the POST. And the data-processing row is left out rather than
        | shown: it is asked under a DIFFERENT permission (`DataRights`) and has
        | its own screen on `/family`, so reporting it here would tell a guardian
        | holding only the payments consent about a document they cannot sign.
        */
        $document = ConsentDocument::DeferredPaymentTerms;
        $student = $this->studentFor($request, $reader, $guardians, $document);

        $state = array_values(array_filter(
            $this->stateFor($student, $registry),
            static fn (array $row): bool => $row['document'] === $document->value,
        ));

        return response()->json(['data' => $state]);
    }

    public function store(
        StoreTermsConsentRequest $request,
        RecordTermsConsent $action,
        ConsentRegistry $registry,
        GuardianDirectory $guardians,
    ): JsonResponse {
        $signer = $this->currentUser($request);
        $document = ConsentDocument::from($request->string('document')->toString());
        $student = $this->studentFor($request, $signer, $guardians, $document);

        try {
            $action->handle(
                $signer,
                $student,
                $document,
                // TrustProxies must stay configured for this to be the person's
                // address rather than the load balancer's — see bootstrap/app.php.
                $request->ip(),
                $request->userAgent(),
            );
        } catch (RuntimeException $e) {
            abort(403, $e->getMessage());
        }

        // The state after signing, in the same shape as the GET, so the screen
        // re-renders from the response instead of asking again — and for a
        // guardian signing for a child, the same one-document shape their GET
        // answered, or the card would suddenly list a document it never offered.
        $state = $this->stateFor($student, $registry);

        if ($student->getKey() !== $signer->getKey()) {
            $state = array_values(array_filter(
                $state,
                static fn (array $row): bool => $row['document'] === $document->value,
            ));
        }

        return response()->json(['data' => $state], 201);
    }

    /**
     * Who this signature is about.
     *
     * A guardian signing for a child arrives with a `student` uuid, and the child
     * is matched INSIDE the authorised list rather than fetched by uuid and then
     * checked. Fetching first is what makes a 404 mean "this uuid is nobody" and
     * a 403 mean "this uuid is somebody else's child" — two answers where there
     * should be one.
     */
    private function studentFor(Request $request, User $signer, GuardianDirectory $guardians, ConsentDocument $document): User
    {
        $uuid = $request->string('student')->toString();

        if ($uuid === '' || $uuid === $signer->uuid) {
            return $signer;
        }

        /*
        | ⚠️ THE PERMISSION FOLLOWS THE DOCUMENT, exactly as
        | `RecordTermsConsent::maySignFor()` decides it. Asking `Payments` for
        | every document refused the one guardian `GuardianInvitation` creates for
        | a self-registered minor — whose link carries `DataRights` alone — at the
        | door, before the Action that would have allowed them was reached: two
        | spellings of «who may sign this», disagreeing.
        */
        $permission = $document === ConsentDocument::DataProcessing
            ? GuardianPermission::DataRights
            : GuardianPermission::Payments;

        $child = $guardians->childrenOf($signer, $permission)
            ->first(fn (User $student): bool => $student->uuid === $uuid);

        abort_if($child === null, 403, 'لا يحقّ لك التوقيع نيابةً عن هذا الطالب.');

        return $child;
    }

    /**
     * Every document, its version in force, and when this person accepted it.
     *
     * The whole list rather than the one that was asked about: a screen that has
     * to know which acceptances are outstanding would otherwise make one request
     * per document and hard-code the list of them.
     *
     * @return list<array<string, mixed>>
     */
    private function stateFor(User $student, ConsentRegistry $registry): array
    {
        $state = [];

        foreach (ConsentDocument::cases() as $document) {
            $version = $registry->currentVersion($document);

            $consent = TermsConsent::query()
                ->where('student_user_id', $student->getKey())
                ->where('document', $document->value)
                ->where('version', $version)
                ->latest('consented_at')
                ->first();

            $state[] = [
                'document' => $document->value,
                'label' => $document->label(),
                'version' => $version,
                // Null means outstanding — either never accepted, or accepted
                // against a version that has since been superseded (FR-049).
                'consented_at' => $consent?->consented_at?->toIso8601String(),
            ];
        }

        return $state;
    }
}
