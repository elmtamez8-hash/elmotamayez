"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, TextareaField } from "@/components/ui/Field";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { userMessage } from "@/lib/errors";
import { grading, type GradingAnswer, type GradingPaper, type MarkInput } from "@/lib/grading";
import { formatDateTime } from "@/lib/labels";

/**
 * One paper, marked.
 *
 * ⚠️ THE CEILING SHOWN IS THE PAPER'S, NOT THE QUESTION'S. `points_possible`
 * comes from the snapshot taken when the student started; a teacher who raised
 * the question from 5 to 10 afterwards would otherwise be invited to award marks
 * the server then refuses, with no way to see why.
 *
 * ⚠️ AND A REVISION ASKS FOR ITS REASON BEFORE IT WILL SEND. The server refuses
 * a reasonless one anyway (FR-032); asking here is what stops the grader
 * discovering that after they have retyped every mark.
 */
export default function GradePaperPage() {
  const params = useParams<{ uuid: string }>();
  const router = useRouter();

  const [paper, setPaper] = useState<GradingPaper | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");

  const load = useCallback(() => {
    setState("loading");

    grading
      .paper(params.uuid)
      .then((response) => {
        setPaper(response.data);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, [params.uuid]);

  useEffect(load, [load]);

  if (state === "loading") return <RowsSkeleton count={3} />;
  if (state === "error" || paper === null) return <ErrorState onRetry={load} />;

  const outstanding = paper.answers.filter((answer) => !answer.is_graded).length;

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">{paper.exam_title ?? "ورقة"}</h1>
        <p className="text-sm text-ink-muted">
          {paper.is_anonymous ? "الطالب مُخفى" : (paper.student?.name ?? "—")} · سُلّمت{" "}
          {formatDateTime(paper.submitted_at)}
        </p>
        <p className="mt-1 text-sm text-ink-muted">
          {/* Named for what it is. A number labelled "الدرجة" over an unmarked
              paper is a result the student never got. */}
          المصحَّح آلياً: <bdi>{Math.round(paper.auto_score)}</bdi>٪ ·{" "}
          {outstanding > 0 ? (
            <span>
              يتبقّى <bdi>{outstanding}</bdi> سؤالاً
            </span>
          ) : (
            <span>اكتمل التصحيح</span>
          )}
        </p>
      </header>

      {paper.answers.map((answer, index) => (
        <AnswerCard
          key={answer.uuid}
          answer={answer}
          index={index}
          onSaved={() => {
            load();
            router.refresh();
          }}
        />
      ))}
    </div>
  );
}

function AnswerCard({
  answer,
  index,
  onSaved,
}: {
  answer: GradingAnswer;
  index: number;
  onSaved: () => void;
}) {
  const hasRubric = answer.criteria.length > 0;

  // Keyed by criterion id, or by 0 for the single mark an essay with no mark
  // scheme carries. One shape for both, so the submit path has no branch.
  const [points, setPoints] = useState<Record<number, string>>(() => initialPoints(answer));
  const [comments, setComments] = useState<Record<number, string>>(() => initialComments(answer));
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const submit = async () => {
    setSaving(true);
    setError("");

    const marks: MarkInput[] = hasRubric
      ? answer.criteria.map((criterion) => ({
          criterion_id: criterion.id,
          points: Number(points[criterion.id] ?? 0),
          comment: comments[criterion.id] ?? null,
        }))
      : [{ criterion_id: null, points: Number(points[0] ?? 0), comment: comments[0] ?? null }];

    try {
      if (answer.is_graded) {
        await grading.revise(answer.uuid, marks, reason);
      } else {
        await grading.grade(answer.uuid, marks);
      }

      onSaved();
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setSaving(false);
    }
  };

  const total = hasRubric
    ? answer.criteria.reduce((sum, criterion) => sum + Number(points[criterion.id] ?? 0), 0)
    : Number(points[0] ?? 0);

  return (
    <Card as="section">
      <div className="mb-4 flex items-start gap-3">
        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-medium text-primary-ink">
          <bdi>{index + 1}</bdi>
        </span>
        <div className="flex-1">
          <p className="font-medium text-ink">{answer.question?.content ?? "—"}</p>
          <p className="mt-1 text-xs text-ink-muted">
            من <bdi>{answer.points_possible}</bdi> درجة
          </p>
        </div>
        {answer.is_graded && <Badge tone="success">مُصحَّح</Badge>}
      </div>

      <blockquote className="mb-4 whitespace-pre-wrap rounded-lg border-s-4 border-primary-soft bg-surface p-3 text-sm text-ink">
        {answer.answer_text === null || answer.answer_text === "" ? (
          // Left blank is not the same as answered badly, and the difference
          // decides whether the grader is reading or awarding zero.
          <span className="text-ink-muted">تركه الطالب بلا إجابة.</span>
        ) : (
          answer.answer_text
        )}
      </blockquote>

      {error !== "" && <Alert tone="danger" title={error} />}

      <div className="space-y-4">
        {hasRubric ? (
          answer.criteria.map((criterion) => (
            <div key={criterion.id} className="grid gap-3 sm:grid-cols-[8rem_1fr]">
              <NumberField
                id={`points-${answer.uuid}-${criterion.id}`}
                label={`${criterion.label} (${criterion.max_points})`}
                value={points[criterion.id] ?? ""}
                onChange={(value) => setPoints((previous) => ({ ...previous, [criterion.id]: value }))}
                min={0}
                max={criterion.max_points}
                step={0.25}
              />
              <TextareaField
                id={`comment-${answer.uuid}-${criterion.id}`}
                label="ملاحظة"
                rows={2}
                value={comments[criterion.id] ?? ""}
                onChange={(value) => setComments((previous) => ({ ...previous, [criterion.id]: value }))}
              />
            </div>
          ))
        ) : (
          <div className="grid gap-3 sm:grid-cols-[8rem_1fr]">
            <NumberField
              id={`points-${answer.uuid}`}
              label="الدرجة"
              value={points[0] ?? ""}
              onChange={(value) => setPoints((previous) => ({ ...previous, 0: value }))}
              min={0}
              max={answer.points_possible}
              step={0.25}
            />
            <TextareaField
              id={`comment-${answer.uuid}`}
              label="ملاحظة للطالب"
              rows={2}
              value={comments[0] ?? ""}
              onChange={(value) => setComments((previous) => ({ ...previous, 0: value }))}
            />
          </div>
        )}

        {answer.is_graded && (
          <TextareaField
            id={`reason-${answer.uuid}`}
            label="سبب التعديل"
            rows={2}
            required
            value={reason}
            onChange={setReason}
            hint="يُحفظ مع الدرجة الجديدة، ويبقى مع القديمة."
          />
        )}
      </div>

      <div className="mt-4 flex items-center gap-4">
        <Button
          onClick={submit}
          loading={saving}
          loadingLabel="جارٍ الحفظ…"
          disabled={answer.is_graded && reason.trim() === ""}
        >
          {answer.is_graded ? "عدّل الدرجة" : "اعتمد الدرجة"}
        </Button>
        <p className="text-sm text-ink-muted">
          المجموع <bdi>{total}</bdi> من <bdi>{answer.points_possible}</bdi>
        </p>
      </div>
    </Card>
  );
}

function initialPoints(answer: GradingAnswer): Record<number, string> {
  const values: Record<number, string> = {};

  for (const mark of answer.marks) {
    values[mark.criterion_id ?? 0] = String(mark.points);
  }

  return values;
}

function initialComments(answer: GradingAnswer): Record<number, string> {
  const values: Record<number, string> = {};

  for (const mark of answer.marks) {
    values[mark.criterion_id ?? 0] = mark.comment ?? "";
  }

  return values;
}
