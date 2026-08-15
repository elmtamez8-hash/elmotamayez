"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckIcon } from "@/components/icons";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { mistakes, type PracticeQuestion } from "@/lib/mistakes";

/**
 * A revision paper built from what the student still gets wrong.
 *
 * ⚠️ THE PAPER IS BUILT ON MOUNT AND HELD IN MEMORY, deliberately. A practice
 * attempt is not something anybody returns to: reloading builds a fresh one from
 * whatever is still standing, which is the correct answer to "what should I
 * revise now" rather than a replay of an hour-old list.
 *
 * ⚠️ AND IT SPENDS NO OFFICIAL ATTEMPT. The paper carries no exam, so the
 * server's attempt allowance never sees it — a student who revises three times
 * still has both of their real sittings.
 */
export default function MistakePracticePage() {
  const [attemptUuid, setAttemptUuid] = useState<string | null>(null);
  const [questions, setQuestions] = useState<PracticeQuestion[]>([]);
  const [answers, setAnswers] = useState<Record<number, number[]>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [score, setScore] = useState<number | null>(null);

  useEffect(() => {
    mistakes
      .practice()
      .then((response) => {
        setAttemptUuid(response.data.uuid);
        setQuestions(response.questions);
        setAnswers(Object.fromEntries(response.questions.map((q) => [q.id, []])));
      })
      .catch((cause: unknown) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, []);

  const toggleOption = (questionId: number, optionId: number) => {
    setAnswers((prev) => {
      const current = prev[questionId] ?? [];
      return {
        ...prev,
        [questionId]: current.includes(optionId)
          ? current.filter((id) => id !== optionId)
          : [...current, optionId],
      };
    });
  };

  const submit = async () => {
    if (attemptUuid === null) return;

    setSubmitting(true);
    setError("");

    try {
      const result = await api.post<{ score: number }>(`/attempts/${attemptUuid}/submit`, {
        // Unanswered questions go up with an empty selection and score zero —
        // the total never shrinks to what happened to be answered.
        answers: Object.entries(answers).map(([id, selected]) => ({
          question_id: parseInt(id, 10),
          selected_option_ids: selected,
        })),
      });

      setScore(result.score);
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <RowsSkeleton count={4} />;

  if (error !== "" && attemptUuid === null) {
    return (
      <div className="mx-auto max-w-2xl">
        <Alert tone="danger" title={error}>
          <Button href="/mistakes" variant="secondary" size="sm">
            عُد إلى دفتر الأخطاء
          </Button>
        </Alert>
      </div>
    );
  }

  if (score !== null) {
    return (
      <div className="mx-auto max-w-2xl space-y-4">
        <Card>
          <h2 className="text-2xl font-bold text-ink">
            نتيجتك <bdi>{score}</bdi>٪
          </h2>
          <p className="mt-2 text-sm text-ink-muted">
            ما أجبت عنه صحيحاً خرج من دفترك. راجع البقيّة، أو ابنِ ورقةً جديدة.
          </p>
          <div className="mt-4 flex gap-2">
            <Button href="/mistakes">دفتر الأخطاء</Button>
          </div>
        </Card>
      </div>
    );
  }

  const answered = Object.values(answers).filter((selected) => selected.length > 0).length;

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">اختبرني في أخطائي</h2>
        <p className="text-ink-muted">
          أسئلة أخطأت فيها ولم تُصلحها بعد. أجبت عن <bdi>{answered}</bdi> من{" "}
          <bdi>{questions.length}</bdi>. لا تُحتسب هذه الورقة في درجاتك.
        </p>
      </div>

      {error !== "" && <Alert tone="danger" title={error} />}

      <div className="space-y-6">
        {questions.map((question, index) => (
          <Card key={question.id} as="section">
            <div className="mb-4 flex items-start gap-3">
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-medium text-primary-ink">
                <bdi>{index + 1}</bdi>
              </span>
              <p className="flex-1 font-medium text-ink">{question.content}</p>
            </div>

            <fieldset className="space-y-2">
              <legend className="sr-only">{question.content}</legend>
              {question.options.map((option) => {
                const selected = (answers[question.id] ?? []).includes(option.id);
                return (
                  <button
                    key={option.id}
                    type="button"
                    aria-pressed={selected}
                    onClick={() => toggleOption(question.id, option.id)}
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
                    {option.content}
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
        سلّم الورقة
      </Button>
    </div>
  );
}
