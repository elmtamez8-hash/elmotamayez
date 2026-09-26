"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextareaField } from "@/components/ui/Field";
import { assignments, stateLabel, type Assignment } from "@/lib/assignments";
import { userMessage } from "@/lib/errors";
import { counted, formatDateTime, NOUNS } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";

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
    <Card as="section">
      <div className="mb-3 flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h4 className="font-medium text-ink">{assignment.title}</h4>

          {/* ⚠️ WHICH SUBJECT AND WITH WHOM — the list spans every teacher the
              student studies with, and until this payload carried them two
              identically-titled homeworks were indistinguishable. */}
          {(assignment.course != null || assignment.teacher != null || cohortName !== null) && (
            <p className="mt-1 text-xs text-ink-muted">
              {[assignment.course?.title, assignment.teacher?.name, cohortName]
                .filter((part) => part != null && part !== "")
                .join(" · ")}
            </p>
          )}

          <p className="mt-1 text-sm text-ink-muted">
            من {counted(assignment.points, { ...NOUNS.points, two: "درجتين" })}
            {assignment.due_at !== null && <> · يُسلَّم قبل {formatDateTime(assignment.due_at)}</>}
          </p>
        </div>

        {mine !== null && <Badge tone={badgeTone(mine.state)}>{stateLabel(mine.state)}</Badge>}
      </div>

      {assignment.description !== null && assignment.description !== "" && (
        <p className="mb-3 whitespace-pre-wrap text-sm text-ink">{assignment.description}</p>
      )}

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
    </Card>
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
