"use client";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { stateLabel, type Assignment } from "@/lib/assignments";
import { formatDateTime } from "@/lib/labels";

/**
 * This course's homework: what is due, what went in, and what being late costs
 * (US2 · FR-018).
 *
 * ⚠️ `due_at` IS THE ASSIGNMENT'S OWN DATE AND NOBODY'S EFFECTIVE ONE. A student
 * with an accommodation has a later deadline and it lives on their own
 * submission — a shared object that quietly differed per reader would announce
 * that the accommodation exists (010 · FR-056). So the extension is read from
 * `my_submission.extension_until` when the server sends one, and the shared date
 * is what everybody else sees.
 *
 * ⚠️ AND `state` ARRIVES ONLY FOR THE ROW'S OWNER. A hand-in stamped after the
 * deadline and labelled `on_time` tells any reader who can subtract that its
 * owner had an extension, so the server omits the pair rather than redacting it
 * — which is why nothing here renders a missing `state` as a default.
 */
export function AssignmentsTab({ assignments }: { assignments: Assignment[] }) {
  if (assignments.length === 0) {
    return (
      <EmptyState
        title="لا واجبات في هذه المادّة بعد"
        description="حين ينشر مدرّسك واجباً ستجده هنا بموعد تسليمه."
      />
    );
  }

  return (
    <div className="space-y-4">
      {assignments.map((assignment, index) => {
        const mine = assignment.my_submission;
        const due = mine?.extension_until ?? assignment.due_at;

        return (
          <div
            key={assignment.uuid}
            className="banner-rise"
            style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
          >
            <Card as="article" padding="sm">
              <div className="mb-2 flex flex-wrap items-start justify-between gap-3">
                <h3 className="font-semibold text-ink">{assignment.title}</h3>

                {/* The word is the carrier; the tone is emphasis on top of it. */}
                {mine?.state !== undefined && (
                  <Badge tone={toneFor(mine.state)}>{stateLabel(mine.state)}</Badge>
                )}
              </div>

              <p className="mb-3 text-sm text-ink-muted">
                {due === null ? (
                  "بلا موعد تسليم."
                ) : (
                  <>
                    التسليم <bdi>{formatDateTime(due)}</bdi>
                    {/* Named rather than implied: «متأخّر» with no consequence
                        beside it is a label, and what the student needs to know
                        is what it costs. */}
                    {mine?.extension_until != null && " (مُمدَّد لك)"}
                  </>
                )}
              </p>

              <div className="mb-3 flex flex-wrap gap-2">
                <Badge>
                  <bdi>{assignment.points}</bdi>&nbsp;درجة
                </Badge>
                {assignment.late_policy === "reject" && (
                  <Badge tone="warning">لا يُقبل بعد الموعد</Badge>
                )}
                {assignment.late_policy === "penalty" && (
                  <Badge tone="warning">
                    خصم&nbsp;<bdi>{assignment.late_penalty_pct_per_day}%</bdi>&nbsp;لكل يوم تأخير
                  </Badge>
                )}
              </div>

              {/* The penalty that was actually applied, after the fact — a
                  different statement from the policy above, and the only one
                  that names a number the student has already paid. */}
              {mine?.late_penalty_applied_pct != null && mine.late_penalty_applied_pct > 0 && (
                <p className="mb-3 text-sm text-ink-muted">
                  طُبِّق خصم تأخير <bdi>{mine.late_penalty_applied_pct}%</bdi>.
                </p>
              )}

              <div className="flex flex-wrap items-center gap-3">
                {/*
                  ⚠️ `/assignments`, NOT `/assignments/{uuid}` — THERE IS NO
                  DETAIL ROUTE. Handing in happens in a form on the list screen,
                  so a per-assignment href would be a 404 on the one control this
                  card exists to offer. The ceiling is that the reader lands on
                  the whole list rather than on this row; a detail page (or an
                  anchor) is the upgrade, and inventing a second submission form
                  inside this tab is not.
                */}
                <Button href="/assignments" size="sm">
                  {mine === null ? "سلِّم الواجب" : "افتح الواجب"}
                </Button>

                {mine?.is_graded === true && (
                  <span className="text-sm text-ink">
                    درجتك <bdi>{mine.score}</bdi> من <bdi>{assignment.points}</bdi>
                  </span>
                )}
              </div>
            </Card>
          </div>
        );
      })}
    </div>
  );
}

function toneFor(state: string) {
  switch (state) {
    case "on_time":
      return "success" as const;
    case "late":
      return "warning" as const;
    case "missed":
      return "danger" as const;
    default:
      return "neutral" as const;
  }
}
