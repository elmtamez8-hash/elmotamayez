"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { classSessions, type SessionEligibility } from "@/lib/class-sessions";

/**
 * Why this session is shut, in words the student can act on (FR-038).
 *
 * ⚠️ IT NAMES THE MISSING PIECE AND NEVER SAYS «غير متاح». A block with no
 * stated cause turns a motivation feature into an outage: the student cannot
 * tell a rule from a bug, so they email their teacher — who cannot see the
 * verdict either. The one thing they cannot work out for themselves is what to
 * go and do.
 *
 * ⚠️ AND IT IS NOT THE GUARD. The server refuses the booking and the join on its
 * own (`BookingEligibility::openingRefusal`); this component explains a refusal
 * that has already been decided. A gate that lived only here would be the defect
 * this product was caught with once — a screen that hides what the API allows.
 */
export function UnlockNotice({ sessionUuid }: { sessionUuid: string }) {
  const [eligibility, setEligibility] = useState<SessionEligibility | null>(null);

  useEffect(() => {
    let cancelled = false;

    classSessions
      .eligibility(sessionUuid)
      .then((response) => {
        if (!cancelled) setEligibility(response.data);
      })
      // Silent: a failed explanation must not replace the page with an error.
      // The server still refuses the action itself.
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [sessionUuid]);

  if (eligibility === null || eligibility.open) return null;

  return (
    <div className="space-y-3">
      {eligibility.unlock.reason !== null && (
        <Alert tone="warning" title={eligibility.unlock.reason}>
          {/* Which rule decided — the teacher's own two rules are otherwise
              indistinguishable from each other when somebody asks why. */}
          {eligibility.unlock.rule_scope === "course"
            ? "شرطٌ خاصٌّ بهذا الكورس."
            : "شرطٌ عامٌّ عند هذا المدرّس."}
        </Alert>
      )}

      {/* Kept apart on purpose: being short of credit and short of homework are
          two different things to go and do. */}
      {eligibility.booking_refusal !== null && (
        <Alert tone="danger" title={eligibility.booking_refusal} />
      )}
    </div>
  );
}
