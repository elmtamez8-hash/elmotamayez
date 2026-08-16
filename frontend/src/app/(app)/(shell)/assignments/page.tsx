"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextareaField } from "@/components/ui/Field";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { assignments, stateLabel, type Assignment } from "@/lib/assignments";
import { formatDateTime } from "@/lib/labels";

/**
 * The student's homework.
 *
 * ⚠️ THE LATE POLICY IS SHOWN BEFORE THE DEADLINE PASSES, not explained after a
 * mark comes back lower than expected. A stated policy exists so it can be read
 * in advance; a penalty a student first meets in their grade is a penalty they
 * reply to their teacher about.
 */
export default function AssignmentsPage() {
  const [items, setItems] = useState<Assignment[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "empty" | "error">("loading");

  const load = useCallback(() => {
    setState("loading");

    assignments
      .list()
      .then((response) => {
        const data = response.data ?? [];
        setItems(data);
        setState(data.length === 0 ? "empty" : "ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">واجباتي</h1>
        <p className="text-sm text-ink-muted">
          ما هو مطلوبٌ منك ومتى، وما سلّمته وما أخذه من درجة.
        </p>
      </header>

      {state === "loading" && <RowsSkeleton count={3} />}
      {state === "error" && <ErrorState onRetry={load} />}
      {state === "empty" && (
        <EmptyState
          title="لا واجبات عليك الآن"
          description="يظهر هنا كلّ واجبٍ ينشره مدرّسك، بموعده وبما سلّمته فيه."
        />
      )}

      {state === "ready" &&
        items.map((assignment) => (
          <AssignmentCard key={assignment.uuid} assignment={assignment} onSubmitted={load} />
        ))}
    </div>
  );
}

function AssignmentCard({
  assignment,
  onSubmitted,
}: {
  assignment: Assignment;
  onSubmitted: () => void;
}) {
  const [answer, setAnswer] = useState(assignment.my_submission?.answer_text ?? "");
  const [file, setFile] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");

  const mine = assignment.my_submission;
  const wantsFile = assignment.submission_type === "file";
  const locked = mine?.is_graded === true;

  const send = async () => {
    setSending(true);
    setError("");

    try {
      await assignments.submit(assignment.uuid, {
        answer_text: wantsFile ? undefined : answer,
        file: file ?? undefined,
      });

      onSubmitted();
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setSending(false);
    }
  };

  return (
    <Card as="section">
      <div className="mb-3 flex items-start justify-between gap-3">
        <div>
          <h2 className="font-medium text-ink">{assignment.title}</h2>
          <p className="mt-1 text-sm text-ink-muted">
            من <bdi>{assignment.points}</bdi> درجة
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
          يُخصم <bdi>{assignment.late_penalty_pct_per_day}</bdi>٪ عن كل يوم تأخير، بحدٍّ أقصى{" "}
          <bdi>{assignment.late_penalty_cap_pct}</bdi>٪.
        </p>
      )}

      {mine?.is_graded === true ? (
        <Alert tone="success" title={`درجتك ${mine.score ?? 0} من ${assignment.points}`}>
          {/* The penalty is named, not left to be inferred from a mark lower
              than the student expected. */}
          {(mine.late_penalty_applied_pct ?? 0) > 0 && (
            <span>
              خُصم <bdi>{mine.late_penalty_applied_pct}</bdi>٪ للتأخير.{" "}
            </span>
          )}
          {mine.feedback}
        </Alert>
      ) : (
        <div className="space-y-3">
          {error !== "" && <Alert tone="danger" title={error} />}

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
