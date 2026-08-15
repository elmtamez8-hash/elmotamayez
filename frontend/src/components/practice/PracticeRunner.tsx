"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckIcon } from "@/components/icons";
import { userMessage } from "@/lib/errors";
import { practice, type PracticePaper, type PracticeResult } from "@/lib/practice";

/**
 * Sits one generated paper and shows it marked.
 *
 * ⚠️ ONE COMPONENT FOR BOTH GENERATORS. "Test me on my mistakes" and the
 * self-generated exam produce the same shape from the same endpoint family, and
 * two screens for one paper is two renderers that drift — the second one being
 * the one that forgets to show the explanations.
 */
export function PracticeRunner({
  paper,
  onRestart,
}: {
  paper: PracticePaper;
  onRestart: () => void;
}) {
  const [answers, setAnswers] = useState<Record<number, number[]>>(
    Object.fromEntries(paper.questions.map((question) => [question.id, []])),
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [result, setResult] = useState<PracticeResult | null>(null);

  const toggle = (questionId: number, optionId: number) =>
    setAnswers((previous) => {
      const current = previous[questionId] ?? [];
      return {
        ...previous,
        [questionId]: current.includes(optionId)
          ? current.filter((id) => id !== optionId)
          : [...current, optionId],
      };
    });

  const submit = async () => {
    setSubmitting(true);
    setError("");

    try {
      await practice.submit(
        paper.uuid,
        // Unanswered questions travel with an empty selection and score zero —
        // the total never shrinks to what happened to be answered.
        Object.entries(answers).map(([id, selected]) => ({
          question_id: parseInt(id, 10),
          selected_option_ids: selected,
        })),
      );

      setResult((await practice.result(paper.uuid)).data);
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setSubmitting(false);
    }
  };

  if (result !== null) {
    return (
      <div className="space-y-4">
        <Card>
          <h2 className="text-2xl font-bold text-ink">
            نتيجتك <bdi>{Math.round(result.score)}</bdi>٪
          </h2>
          <p className="mt-2 text-sm text-ink-muted">
            ورقةُ تدريب: لا تُحتسب في درجاتك ولا تستهلك محاولةً رسمية.
          </p>
          <div className="mt-4">
            <Button onClick={onRestart}>ورقةٌ جديدة</Button>
          </div>
        </Card>

        {result.questions.map((review, index) => (
          <Card key={review.id}>
            <div className="mb-3 flex items-start gap-3">
              <span
                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-medium ${
                  review.is_correct
                    ? "bg-success-soft text-success-ink"
                    : "bg-danger-soft text-danger-ink"
                }`}
              >
                <bdi>{index + 1}</bdi>
              </span>
              <p className="flex-1 font-medium text-ink">{review.content}</p>
            </div>

            <dl className="grid gap-3 sm:grid-cols-2">
              <div>
                <dt className="text-xs text-ink-muted">إجابتك</dt>
                {/* Left blank is not the same as answered wrongly, and the
                    difference is the strongest signal on the page. */}
                <dd className="text-sm text-ink">
                  {review.was_answered ? review.your_answer.join(" · ") : "تركته بلا إجابة"}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-ink-muted">الصواب</dt>
                <dd className="text-sm text-ink">{review.correct_answer.join(" · ")}</dd>
              </div>
            </dl>

            {review.explanation !== null && review.explanation !== "" && (
              <p className="mt-3 rounded-lg bg-primary-soft/40 p-3 text-sm text-ink">
                {review.explanation}
              </p>
            )}
          </Card>
        ))}
      </div>
    );
  }

  const answered = Object.values(answers).filter((selected) => selected.length > 0).length;

  return (
    <div className="space-y-6">
      <div>
        <p className="text-ink-muted">
          أجبت عن <bdi>{answered}</bdi> من <bdi>{paper.questions.length}</bdi>.
        </p>
        {/* FR-023: a paper shorter than the request is a correct answer, said
            out loud. Silent, it reads as a number the student typed for nothing. */}
        {paper.delivered_count < paper.requested_count && (
          <p className="mt-1 text-sm text-ink-muted">
            طلبت <bdi>{paper.requested_count}</bdi> سؤالاً، والمتاح لك الآن{" "}
            <bdi>{paper.delivered_count}</bdi>.
          </p>
        )}
      </div>

      {error !== "" && <Alert tone="danger" title={error} />}

      {paper.questions.map((question, index) => (
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
                  onClick={() => toggle(question.id, option.id)}
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

      <Button fullWidth size="lg" loading={submitting} loadingLabel="جارٍ التصحيح…" onClick={submit}>
        صحّح الورقة
      </Button>
    </div>
  );
}
