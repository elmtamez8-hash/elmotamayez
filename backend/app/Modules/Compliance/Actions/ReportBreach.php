<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Models\BreachReport;
use App\Shared\Actions\Action;

/**
 * Record a reported breach (FR-040 · SC-020).
 *
 * ⚠️ THE REPORTER IS OPTIONAL AND THAT IS THE WHOLE POINT OF THE ROUTE. FR-040
 * asks for a PUBLISHED path, which means an outside security researcher uses it —
 * and the best-known leaks are reported by people who hold no account. An Action
 * that required a `User` would restrict the report to the population least likely
 * to be making it.
 *
 * ⚠️ AND NOTHING HERE LOOKS ANYTHING UP. `reporter_contact` is stored as typed,
 * never resolved against `users`: a report that behaved differently for a contact
 * that matches an account is an oracle answering "does this address have an
 * account here" to anybody who asks, from an unauthenticated route. Same rule as
 * the payment webhook's uniform `202`. The scope of the incident — which
 * categories, how many people — is not accepted here either; staff fill that in
 * during triage, or anyone could assert the size of an incident into our own
 * record of it.
 */
class ReportBreach extends Action
{
    public function handle(string $description, ?string $reporterContact = null, ?User $reporter = null): BreachReport
    {
        return BreachReport::query()->create([
            'reported_by_user_id' => $reporter?->getKey(),
            'reporter_contact' => $reporterContact,
            'description' => $description,
        ]);
    }
}
