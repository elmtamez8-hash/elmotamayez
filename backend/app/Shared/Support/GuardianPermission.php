<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * What a guardian is allowed to see and be told about, for one student.
 *
 * Lives in Shared rather than in Identity because it is the shared vocabulary of
 * two modules: Identity stores it on the relation, Notifications asks with it.
 * Putting it in either one would make the other reach across a module boundary
 * for a definition (Constitution III).
 *
 * One permission covers both reading and receiving. A guardian who may not see a
 * student's financial record has no business being sent a payment reminder about
 * it either, and splitting the two produced no case where they should differ.
 */
enum GuardianPermission: string
{
    case Attendance = 'attendance';
    case Payments = 'payments';
    case Schedule = 'schedule';
    case Results = 'results';
    case AcademicWarnings = 'academic_warnings';

    /**
     * Consent to processing the student's data, and the right to ask for it
     * (spec 013 · R6).
     *
     * ⚠️ WITHOUT IT THE WHOLE PHASE HANGS OFF `Payments`. `RecordTermsConsent`
     * asks `isAuthorised($signer, $student, GuardianPermission::Payments)` — the
     * only value that existed that came close — so until now a guardian could not
     * consent to the processing of their own child's data unless they had also
     * been granted authority over the money. A coupling with no meaning, and it
     * made 013's own rule ("an authorised guardian grants, an unauthorised one
     * does not") impossible to express at all: there was nothing to be authorised
     * FOR.
     *
     * It is also the permission a data-rights request is checked against, which
     * is why `RELATIONS_VIEW_STUDENT` opens no such request: that one is held by
     * every teacher and assistant, and would be a cross-workspace export of a
     * child's entire record.
     */
    case DataRights = 'data_rights';

    /**
     * Writing to the child's teachers in the child's own private conversation,
     * and reading it (2026-09-28, «تواصل مع المدرّس»).
     *
     * ⚠️ A PERMISSION OF ITS OWN, NOT ONE OF THE SIX ABOVE. Each of those opens a
     * category of the child's RECORD; this one opens the child's CORRESPONDENCE
     * and lets an adult speak in the child's thread. Hanging it off `Schedule` or
     * `Results` would make «may I write to my son's teacher» depend on a box
     * ticked for a different reason, and unticking marks would silently take the
     * conversation away.
     *
     * ⚠️ AND IT IS NOT BACKFILLED onto relations made before it existed. A grant
     * the student never saw would widen what every existing guardian may read —
     * the direction `GuardianDirectory::permissionsFor()` calls unsafe. A new
     * link receives it by default (`LinkGuardianData` defaults to `values()`),
     * and an existing one gains it when the permissions are edited on /family.
     */
    case Messages = 'messages';

    public function label(): string
    {
        return match ($this) {
            self::Attendance => 'الحضور والغياب',
            self::Payments => 'المدفوعات والمستحقّات',
            self::Schedule => 'المواعيد والحصص',
            self::Results => 'النتائج والدرجات',
            self::AcademicWarnings => 'الإنذارات الأكاديمية',
            self::DataRights => 'الموافقة على معالجة البيانات وطلب حقوقها',
            self::Messages => 'مراسلة المدرّسين باسم الطالب',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
