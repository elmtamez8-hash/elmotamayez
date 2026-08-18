"use client";

import { use, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckIcon } from "@/components/icons";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";

interface AttemptResponse {
  attempt: { uuid: string; status: string };
  questions: Array<{
    id: number;
    type: string;
    content: string;
    points: number;
    options: Array<{ id: number; content: string }>;
  }>;
}

/** "درجة / درجتان / N درجات" — Arabic duals and plurals, not an English "s". */
function pointsLabel(points: number): string {
  if (points === 1) return "درجة واحدة";
  if (points === 2) return "درجتان";
  if (points >= 3 && points <= 10) return `${points} درجات`;
  return `${points} درجة`;
}

export default function TakeExamPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);
  const router = useRouter();

  const [data, setData] = useState<AttemptResponse | null>(null);
  const [answers, setAnswers] = useState<Record<number, number[]>>({});
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .post<AttemptResponse>(`/exams/${uuid}/attempts`)
      .then((res) => {
        setData(res);
        const initial: Record<number, number[]> = {};
        res.questions.forEach((q) => {
          initial[q.id] = [];
        });
        setAnswers(initial);
      })
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [uuid]);

  /*
    One answer per question — selecting replaces, it never accumulates.

    ⚠️ IT USED TO ADD, WHICH TURNED A SECOND TAP INTO A GUARANTEED ZERO ON A GRADED
    PAPER. `GradeAttempt::matchesSnapshot()` compares the answer SETS and a question
    carries exactly one correct option, so tapping a second choice made a right
    answer wrong — with nothing on the screen saying a second tap was not allowed,
    and no way back once the attempt was submitted.

    Tapping the chosen option again clears it, so a student can still leave a
    question unanswered on purpose.
  */
  const toggleOption = (questionId: number, optionId: number) => {
    setAnswers((prev) => ({
      ...prev,
      [questionId]: (prev[questionId] ?? []).includes(optionId) ? [] : [optionId],
    }));
  };

  const submit = async () => {
    if (!data) return;
    setSubmitting(true);
    setError("");

    try {
      // Unanswered questions are submitted with an empty selection and graded
      // as zero — the exam total never shrinks to what was answered.
      const payload = Object.entries(answers).map(([qId, optionIds]) => ({
        question_id: parseInt(qId, 10),
        selected_option_ids: optionIds,
      }));

      const result = await api.post<{ uuid: string }>(
        `/attempts/${data.attempt.uuid}/submit`,
        { answers: payload },
      );
      router.push(`/exams/${result.uuid}/result`);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <RowsSkeleton count={4} />;

  if (!data) {
    return (
      <div className="mx-auto max-w-2xl">
        <Alert tone="danger" title={error || "تعذّر بدء الاختبار."}>
          <Button href="/exams" variant="secondary" size="sm">
            عُد إلى الاختبارات
          </Button>
        </Alert>
      </div>
    );
  }

  const answered = Object.values(answers).filter((a) => a.length > 0).length;

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">اختبار جارٍ</h2>
        <p className="text-ink-muted">
          أجب عن الأسئلة ثم سلّم. أجبت عن <bdi>{answered}</bdi> من{" "}
          <bdi>{data.questions.length}</bdi>.
        </p>
      </div>

      {error && <Alert tone="danger" title={error} />}

      <div className="space-y-6">
        {data.questions.map((q, idx) => (
          <Card key={q.id} as="section">
            <div className="mb-4 flex items-start gap-3">
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-medium text-primary-ink">
                <bdi>{idx + 1}</bdi>
              </span>
              <div className="flex-1">
                <p className="font-medium text-ink">{q.content}</p>
                <p className="mt-1 text-xs text-ink-muted">{pointsLabel(q.points)}</p>
              </div>
            </div>

            {/* A fieldset, not a bare div: the question text is the group's
                label, which is how a screen reader ties the options to it. */}
            <fieldset className="space-y-2">
              <legend className="sr-only">{q.content}</legend>
              {q.options.map((opt) => {
                const selected = (answers[q.id] ?? []).includes(opt.id);
                return (
                  <button
                    key={opt.id}
                    type="button"
                    aria-pressed={selected}
                    onClick={() => toggleOption(q.id, opt.id)}
                    className={`flex w-full items-center gap-3 rounded-lg border p-3 text-start text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                      selected
                        ? "border-primary bg-primary-soft text-ink"
                        : "border-line text-ink hover:border-primary/40"
                    }`}
                  >
                    <span
                      aria-hidden="true"
                      className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border ${
                        selected ? "border-primary bg-primary text-white" : "border-line"
                      }`}
                    >
                      {selected && <CheckIcon className="h-3 w-3" />}
                    </span>
                    {opt.content}
                  </button>
                );
              })}
            </fieldset>
          </Card>
        ))}
      </div>

      <Button
        fullWidth
        size="lg"
        loading={submitting}
        loadingLabel="جارٍ التسليم…"
        onClick={submit}
      >
        سلّم الاختبار
      </Button>
    </div>
  );
}
