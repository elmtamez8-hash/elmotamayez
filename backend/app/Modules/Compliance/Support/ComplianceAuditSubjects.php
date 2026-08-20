<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\BreachReport;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Models\DataProcessor;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Compliance\Models\TeacherOffboarding;

/**
 * What the compliance audit trail is allowed to be about.
 *
 * The third member of a family — `BillingAuditSubjects` and
 * `SettlementAuditSubjects` — and it exists for the reason each of those writes
 * down: `activity_log` is ONE SHARED TABLE that seven modules write into. A reader
 * that fetched the table and then dropped everyone else's rows is one forgotten
 * branch away from showing a compliance officer a student's payment history, or a
 * finance officer the reason a court held someone's record.
 *
 * ⚠️ SO THE ENDPOINT NEVER ASKS FOR THE TABLE. It asks for these six subject
 * types, and there is no branch in which it asks for more.
 */
final class ComplianceAuditSubjects
{
    /**
     * Model class → the slug the payload names it by.
     *
     * A slug rather than the class name, for the reason its siblings give: a class
     * name on the wire is a map of the codebase handed to whoever holds a token,
     * and it changes under a rename, which a stored audit entry must not.
     *
     * @var array<class-string, string>
     */
    public const MAP = [
        DataRequest::class => 'data_request',
        LegalHold::class => 'legal_hold',
        BreachReport::class => 'breach_report',
        TeacherOffboarding::class => 'teacher_offboarding',
        DataCategory::class => 'data_category',
        DataProcessor::class => 'data_processor',
    ];

    /** @return list<class-string> */
    public static function types(): array
    {
        return array_keys(self::MAP);
    }

    public static function slugFor(string $subjectType): ?string
    {
        return self::MAP[$subjectType] ?? null;
    }
}
