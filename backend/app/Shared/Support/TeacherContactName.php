<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * The name «راسِل …» carries (owner decision 2026-09-28).
 *
 * ⛔ IT USED TO BE THE WORKSPACE'S NAME ALONE, so inside an academy the button
 * read «راسل Nour Academy» — a student who studies with one named teacher there
 * was offered a building. The message still goes to the whole workspace team (the
 * conversation is keyed on the workspace and nothing about that changed); what
 * changed is the name on the button: the COURSE's teacher, with the academy in
 * brackets — «فلان (Nour Academy)».
 *
 * A solo teacher's own workspace IS the teacher, so repeating it —
 * «فلان (فلان)» — says nothing twice; the teacher's name stands alone. And when
 * the author is gone (`courses.created_by` is nullable) the workspace name is the
 * one honest answer left.
 *
 * ⚠️ NO HONORIFIC IS ADDED. The owner's example read «أ. فلان», but a display
 * name may already carry «أ.» or «د.», and a prefix written here would print it
 * twice on every one of those.
 *
 * Built on the server for the reason every label in this tree is: the client
 * would need a second copy of the rule, and it would drift.
 */
final class TeacherContactName
{
    public static function of(?string $teacherName, ?string $workspaceName, bool $soloTeacher): string
    {
        $teacher = trim((string) $teacherName);
        $workspace = trim((string) $workspaceName);

        if ($teacher === '') {
            return $workspace !== '' ? $workspace : 'المدرّس';
        }

        if ($soloTeacher || $workspace === '' || $workspace === $teacher) {
            return $teacher;
        }

        return $teacher.' ('.$workspace.')';
    }
}
