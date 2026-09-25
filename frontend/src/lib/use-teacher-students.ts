"use client";

import { useEffect, useState } from "react";

import { useAuth } from "./auth-context";
import { billing } from "./billing";
import { can, P } from "./permissions";

export type TeacherStudent = { uuid: string; name: string };

/** A class longer than this many pages of fifty is not a picker's job. */
export const MAX_STUDENT_PAGES = 20;

/**
 * The teacher's own students, for a picker that grants one of them something.
 *
 * ⚠️ READ FROM THE AUTHORISER'S OWN PREDICATE. Every grant this feeds —
 * accommodation, deadline extension, unlock exemption — refuses with a 404
 * unless the student holds an ACTIVE enrolment in this workspace
 * (`hasActiveEnrollmentInWorkspace`), and answers «no such person» and «not
 * yours» identically on purpose, so a free-typed uuid fails with a sentence
 * that cannot say why. The balances panel walks exactly those enrolments; it is
 * one row per enrolment, so a student in two courses folds into one option.
 *
 * ⚠️ AND IT NEEDS `billing.balance.view`. `canPick` false is an answer the
 * screen must SAY (an assistant without it sees an explanation, not an empty
 * select that looks like a class with nobody in it).
 */
export function useTeacherStudents(): {
  canPick: boolean;
  students: TeacherStudent[] | null;
  failed: boolean;
} {
  const { user } = useAuth();
  const canPick = can(user, P.billingBalanceView);

  const [students, setStudents] = useState<TeacherStudent[] | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!canPick) return;

    let cancelled = false;

    (async () => {
      const seen = new Map<string, string>();
      let page = 1;
      let last = 1;

      do {
        const response = await billing.students(page);

        for (const row of response.data ?? []) {
          if (!seen.has(row.student_uuid)) seen.set(row.student_uuid, row.student_name);
        }

        last = response.meta?.last_page ?? 1;
        page += 1;
      } while (page <= last && page <= MAX_STUDENT_PAGES);

      if (!cancelled) {
        setStudents(
          [...seen.entries()]
            .map(([uuid, name]) => ({ uuid, name }))
            .sort((a, b) => a.name.localeCompare(b.name, "ar")),
        );
      }
    })().catch(() => {
      if (!cancelled) setFailed(true);
    });

    return () => {
      cancelled = true;
    };
  }, [canPick]);

  return { canPick, students, failed };
}
