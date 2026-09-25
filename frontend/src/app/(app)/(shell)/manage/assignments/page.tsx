"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { AssignmentIcon } from "@/components/icons";
import { NumberField, TextareaField } from "@/components/ui/Field";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { assignments, stateLabel, type Assignment, type Submission } from "@/lib/assignments";
import { formatDateTime } from "@/lib/labels";
import type { Course } from "@/lib/types";

import { AssignmentForm } from "./AssignmentForm";

/**
 * The teacher's homework: what is set, and what is waiting to be marked.
 *
 * ⚠️ THE DRAFT IS SHOWN AS A DRAFT, and it is the teacher's own list that
 * legitimately contains one. A draft blocks nothing (FR-042) — US7's gate
 * ignores it — so the badge is not decoration: a teacher who thinks unfinished
 * homework is holding their class back will publish it half-written.
 */
export default function ManageAssignmentsPage() {
  const [items, setItems] = useState<Assignment[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "empty" | "error">("loading");
  const [open, setOpen] = useState<string | null>(null);
  // `"new"`, the assignment being edited, or nothing — one form on the page.
  const [editing, setEditing] = useState<Assignment | "new" | null>(null);
  const [courses, setCourses] = useState<Course[]>([]);
  // A refused publish, against the row that was refused. It used to be
  // `.catch(() => load())`: the server's «واجبٌ بلا موعد لا يُنشر» was thrown
  // away and the teacher watched the button do nothing.
  const [publishError, setPublishError] = useState<{ uuid: string; message: string } | null>(null);

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

  // The course picker. A failure leaves it empty, which still offers «كل طلابي»
  // — the form stays usable rather than blocked on a list.
  useEffect(() => {
    api
      .get<{ data: Course[] }>("/courses?per_page=200")
      .then((response) => setCourses(response.data ?? []))
      .catch(() => setCourses([]));
  }, []);

  const publish = (uuid: string) => {
    setPublishError(null);

    assignments
      .publish(uuid)
      .then(load)
      .catch((cause: unknown) => setPublishError({ uuid, message: userMessage(cause) }));
  };

  const saved = () => {
    setEditing(null);
    load();
  };

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        Icon={AssignmentIcon}
        title="الواجبات"
        description="ما نشرته لطلابك، وكم سلّم منهم، وما ينتظر تصحيحك."
      />

      {editing === null ? (
        <Button onClick={() => setEditing("new")}>واجب جديد</Button>
      ) : (
        <AssignmentForm
          key={editing === "new" ? "new" : editing.uuid}
          editing={editing === "new" ? null : editing}
          courses={courses}
          onSaved={saved}
          onCancel={() => setEditing(null)}
        />
      )}

      {state === "loading" && <RowsSkeleton count={3} />}
      {state === "error" && <ErrorState onRetry={load} />}
      {state === "empty" && (
        <EmptyState
          title="لا واجبات بعد"
          description="اضغط «واجب جديد» لتكتب أوّل واجب. يُحفَظ مسوّدةً لا يراها الطلاب حتى تنشره."
        />
      )}

      {state === "ready" &&
        items.map((assignment) => (
          <Card key={assignment.uuid} as="section" interactive>
            <div className="mb-3 flex items-start justify-between gap-3">
              <div>
                <h3 className="font-medium text-ink">{assignment.title}</h3>
                <p className="mt-1 text-sm text-ink-muted">
                  من <bdi>{assignment.points}</bdi> درجة
                  {assignment.due_at !== null && (
                    <> · الموعد {formatDateTime(assignment.due_at)}</>
                  )}
                </p>
              </div>
              <Badge tone={assignment.status === "published" ? "success" : "neutral"}>
                {assignment.status === "published" ? "منشور" : "مسوّدة"}
              </Badge>
            </div>

            <p className="mb-3 text-sm text-ink-muted">
              سلّم <bdi>{assignment.submitted_count ?? 0}</bdi> · ينتظر التصحيح{" "}
              <bdi>{assignment.pending_count ?? 0}</bdi>
            </p>

            <div className="flex flex-wrap gap-3">
              <Button
                variant="secondary"
                onClick={() => setOpen(open === assignment.uuid ? null : assignment.uuid)}
              >
                {open === assignment.uuid ? "أخفِ التسليمات" : "التسليمات"}
              </Button>
              {assignment.status !== "published" && (
                <Button
                  variant="ghost"
                  onClick={() => publish(assignment.uuid)}
                >
                  انشره
                </Button>
              )}
              <Button variant="ghost" onClick={() => setEditing(assignment)}>
                عدّل
              </Button>
            </div>

            {publishError !== null && publishError.uuid === assignment.uuid && (
              <div className="mt-3">
                <Alert tone="danger" title={publishError.message} />
              </div>
            )}

            {open === assignment.uuid && (
              <SubmissionList assignmentUuid={assignment.uuid} points={assignment.points} />
            )}
          </Card>
        ))}
    </div>
  );
}

function SubmissionList({ assignmentUuid, points }: { assignmentUuid: string; points: number }) {
  const [rows, setRows] = useState<Submission[] | null>(null);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setFailed(false);

    assignments
      .submissions(assignmentUuid)
      .then((response) => setRows(response.data ?? []))
      .catch(() => setFailed(true));
  }, [assignmentUuid]);

  useEffect(load, [load]);

  if (failed) return <ErrorState onRetry={load} />;
  if (rows === null) return <RowsSkeleton count={2} />;

  if (rows.length === 0) {
    return <p className="mt-4 text-sm text-ink-muted">لم يسلّم أحدٌ بعد.</p>;
  }

  return (
    <div className="mt-4 space-y-4 border-t border-line pt-4">
      {rows.map((row) => (
        <SubmissionRow
          key={row.uuid}
          assignmentUuid={assignmentUuid}
          row={row}
          points={points}
          onGraded={load}
        />
      ))}
    </div>
  );
}

function badgeTone(state: string | undefined) {
  switch (state) {
    case "late":
      return "warning" as const;
    case "missed":
      return "danger" as const;
    case "pending":
      return "info" as const;
    default:
      return "success" as const;
  }
}

function SubmissionRow({
  assignmentUuid,
  row,
  points,
  onGraded,
}: {
  assignmentUuid: string;
  row: Submission;
  points: number;
  onGraded: () => void;
}) {
  const [score, setScore] = useState(row.score === null ? "" : String(row.score));
  const [feedback, setFeedback] = useState(row.feedback ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [scoreError, setScoreError] = useState("");
  const [opening, setOpening] = useState(false);
  const [fileError, setFileError] = useState("");

  /*
   | ⚠️ THE FILE IS FETCHED, NEVER LINKED. The row used to say only that a file
   | existed (`has_file`) — the teacher could see a worksheet had been handed in
   | and had no way to read it. The route is behind `auth:sanctum`, so a plain
   | link answers 401; `assignments.openFile()` fetches it with the bearer.
   */
  const openFile = async () => {
    setOpening(true);
    setFileError("");

    try {
      await assignments.openFile(assignmentUuid, row.uuid);
    } catch (cause: unknown) {
      setFileError(userMessage(cause));
    } finally {
      setOpening(false);
    }
  };

  const save = async () => {
    /*
     | ⚠️ AN EMPTY BOX IS NOT A ZERO. `Number("")` is 0, so pressing «اعتمد» on a
     | box left blank recorded a zero on the student's work — a mark the teacher
     | never gave, and one that reads to the student and their guardian exactly
     | like a real one.
     */
    if (score.trim() === "" || !Number.isFinite(Number(score))) {
      setScoreError("أدخل الدرجة قبل اعتمادها.");

      return;
    }

    setScoreError("");
    setSaving(true);
    setError("");

    try {
      await assignments.grade(row.uuid, Number(score), feedback);
      onGraded();
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <p className="font-medium text-ink">{row.student?.name ?? "—"}</p>
        <Badge tone={badgeTone(row.state)}>
          {stateLabel(row.state)}
        </Badge>
      </div>

      {row.submitted_at != null && (
        <p className="text-xs text-ink-muted">سُلّم {formatDateTime(row.submitted_at)}</p>
      )}

      {row.answer_text !== null && row.answer_text !== "" && (
        <blockquote className="whitespace-pre-wrap rounded-lg border-s-4 border-primary-soft bg-surface p-3 text-sm text-ink">
          {row.answer_text}
        </blockquote>
      )}

      {row.has_file && (
        <div>
          <Button variant="secondary" onClick={openFile} loading={opening} loadingLabel="جارٍ التنزيل…">
            نزّل الملف المرفق
          </Button>
        </div>
      )}

      {fileError !== "" && <Alert tone="danger" title={fileError} />}

      {error !== "" && <Alert tone="danger" title={error} />}

      {/* ⚠️ `pending` BELONGS ON THIS SIDE OF THE BRANCH TOO. It means the
          student was given longer and has not handed in yet — the server refuses
          to mark it (422), so a grade box here is a form that cannot be
          submitted, offered beside the one student who was told they had time. */}
      {row.state === "missed" || row.state === "pending" ? (
        <p className="text-sm text-ink-muted">
          {row.state === "pending" ? "مُنح مهلةً ولم يسلّم بعد." : "لا شيء سُلّم لتصحيحه."}
        </p>
      ) : (
        <div className="grid gap-3 sm:grid-cols-[8rem_1fr]">
          <NumberField
            id={`score-${row.uuid}`}
            label={`الدرجة (${points})`}
            value={score}
            onChange={setScore}
            error={scoreError || undefined}
            min={0}
            max={points}
            step={0.25}
          />
          <TextareaField
            id={`feedback-${row.uuid}`}
            label="ملاحظة"
            rows={2}
            value={feedback}
            onChange={setFeedback}
          />
        </div>
      )}

      {row.state !== "missed" && row.state !== "pending" && (
        <div className="flex items-center gap-3">
          <Button onClick={save} loading={saving} loadingLabel="جارٍ الحفظ…">
            {row.is_graded ? "عدّل الدرجة" : "اعتمد الدرجة"}
          </Button>
          {/* The penalty is stated on the row, not left to be inferred from a
              mark lower than the number the teacher just typed. */}
          {(row.late_penalty_applied_pct ?? 0) > 0 && (
            <p className="text-sm text-ink-muted">
              خُصم <bdi>{row.late_penalty_applied_pct}</bdi>٪ للتأخير.
            </p>
          )}
        </div>
      )}
    </div>
  );
}
