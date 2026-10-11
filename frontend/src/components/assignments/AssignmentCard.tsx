"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { AssignmentIcon, ClockIcon } from "@/components/icons";
import { TextareaField } from "@/components/ui/Field";
import { assignments, stateLabel, type Assignment } from "@/lib/assignments";
import { userMessage } from "@/lib/errors";
import { counted, formatDateTime, NOUNS } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";
import { MarkdownText } from "@/components/ui/MarkdownText";

/**
 * One piece of homework, with the hand-in beside it.
 *
 * Shared by two screens and deliberately one component: the homework list, and
 * the lesson page of an assignment item placed in a course's curriculum. Two
 * hand-in forms would be two places for the late-policy sentence, the graded
 * lock and the «replace your hand-in» wording to drift apart — and the lesson
 * page is where a student is most likely to hand the work in.
 */
export function AssignmentCard({
  assignment,
  cohortName,
  onSubmitted,
}: {
  assignment: Assignment;
  cohortName: string | null;
  onSubmitted: () => void;
}) {
  const [answer, setAnswer] = useState(assignment.my_submission?.answer_text ?? "");
  const [file, setFile] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");

  const mine = assignment.my_submission;
  const wantsFile = assignment.submission_type === "file";
  const locked = mine?.is_graded === true;

  const send = () => {
    setSending(true);
    setError("");

    assignments
      .submit(assignment.uuid, {
        answer_text: wantsFile ? undefined : answer,
        file: file ?? undefined,
      })
      .then(onSubmitted)
      .catch((cause: unknown) => setError(userMessage(cause)))
      .finally(() => setSending(false));
  };

  return (
    <section className="overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm">
      {/* The bold band (owner request 2026-10-11), in the group card's language:
          the brand colour while the homework still waits on the student, the
          pale tint once it is handed in — so a list reads «what is left». */}
      <header
        className={`relative isolate overflow-hidden px-5 py-4 ${
          mine?.submitted_at == null ? "bg-squares bg-primary text-white" : "bg-primary-soft text-primary-ink"
        }`}
      >
        <AssignmentIcon
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-6 -end-4 -z-10 h-24 w-24 opacity-10"
        />

        <div className="flex items-start justify-between gap-3">
          <h4 className="min-w-0 text-lg font-extrabold leading-snug">{assignment.title}</h4>
          {mine !== null && (
            <span className="shrink-0 rounded-full bg-surface-raised p-0.5 shadow-sm">
              <Badge tone={badgeTone(mine.state)}>{stateLabel(mine.state)}</Badge>
            </span>
          )}
        </div>

        {/* ⚠️ WHICH SUBJECT AND WITH WHOM — the list spans every teacher the
            student studies with, and until this payload carried them two
            identically-titled homeworks were indistinguishable. */}
        {(assignment.course != null || assignment.teacher != null || cohortName !== null) && (
          <p className="mt-1 text-xs font-semibold opacity-85">
            {[assignment.course?.title, assignment.teacher?.name, cohortName]
              .filter((part) => part != null && part !== "")
              .join(" · ")}
          </p>
        )}

        <div className="mt-3 flex flex-wrap gap-2 text-xs font-bold text-primary-ink">
          <span className="rounded-full bg-surface-raised px-3 py-1 shadow-sm">
            من {counted(assignment.points, { ...NOUNS.points, two: "درجتين" })}
          </span>
          {assignment.due_at !== null && (
            <span className="inline-flex items-center gap-1 rounded-full bg-surface-raised px-3 py-1 shadow-sm">
              <ClockIcon className="h-3.5 w-3.5" aria-hidden />
              يُسلَّم قبل {formatDateTime(assignment.due_at)}
            </span>
          )}
        </div>
      </header>

      <div className="p-5">
      <MarkdownText html={assignment.description_html} className="mb-3" />

      {/* Said before the deadline, not after the mark. */}
      {assignment.late_policy === "reject" && (
        <p className="mb-3 text-sm text-ink-muted">لا يُقبل التسليم بعد الموعد.</p>
      )}
      {assignment.late_policy === "penalty" && (
        <p className="mb-3 text-sm text-ink-muted">
          يُخصم <bdi>{arabicNumber(assignment.late_penalty_pct_per_day)}</bdi>٪ عن كل يوم تأخير، بحدٍّ أقصى{" "}
          <bdi>{arabicNumber(assignment.late_penalty_cap_pct)}</bdi>٪.
        </p>
      )}

      {mine?.is_graded === true ? (
        <Alert tone="success" title={`درجتك ${arabicNumber(mine.score ?? 0)} من ${arabicNumber(assignment.points)}`}>
          {/* The penalty is named, not left to be inferred from a mark lower
              than the student expected. */}
          {(mine.late_penalty_applied_pct ?? 0) > 0 && (
            <span>
              خُصم <bdi>{arabicNumber(mine.late_penalty_applied_pct ?? 0)}</bdi>٪ للتأخير.{" "}
            </span>
          )}
          {mine.feedback}
        </Alert>
      ) : (
        <div className="space-y-3">
          {error !== "" && <Alert tone="danger" title="تعذّر التسليم">{error}</Alert>}

          {wantsFile ? (
            <label className="block text-sm text-ink">
              <span className="mb-1 block">ارفع ملفك</span>
              <input
                type="file"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                className="block w-full rounded-lg border border-line p-2 text-sm text-ink"
              />
            </label>
          ) : (
            <TextareaField
              id={`answer-${assignment.uuid}`}
              label="إجابتك"
              rows={4}
              value={answer}
              onChange={setAnswer}
            />
          )}

          <Button onClick={send} loading={sending} loadingLabel="جارٍ التسليم…" disabled={locked}>
            {mine?.submitted_at != null ? "استبدل التسليم" : "سلّم"}
          </Button>
        </div>
      )}
      </div>
    </section>
  );
}

function badgeTone(state: string | undefined) {
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
