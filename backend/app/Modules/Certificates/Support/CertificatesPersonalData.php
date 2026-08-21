<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Support;

use App\Modules\Certificates\Models\Certificate;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;

/**
 * Certificates's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class CertificatesPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'certificates';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['certificate'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        // A certificate is an academic result, so it answers to the same gate the
        // marks do: a guardian granted "attendance" alone is not told what their
        // child passed.
        if (! $subject->mayReceive(GuardianPermission::Results)) {
            return;
        }

        yield from ExportWalk::keyed(
            'certificate',
            Certificate::query()
                ->withoutWorkspaceScope()
                ->leftJoin('courses', 'courses.id', '=', 'certificates.course_id')
                ->where('certificates.student_user_id', $subject->user->getKey())
                ->select(['certificates.*', 'courses.title as course_title']),
            fn (Certificate $certificate): array => [
                'uuid' => $certificate->uuid,
                'certificate_number' => $certificate->certificate_number,
                // Their own handle on their own credential. It is printed on the
                // document they already hold, so withholding it here would leave
                // the export less complete than the certificate itself.
                'verification_code' => $certificate->verification_code,
                'course_title' => $certificate->getAttribute('course_title'),
                'issue_reason' => $certificate->issue_reason,
                'issued_at' => ExportWalk::at($certificate->issued_at),
            ],
            column: 'certificates.id',
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        // TODO(013-US4): erase or anonymise this module's rows for the subject.
        return 0;
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
