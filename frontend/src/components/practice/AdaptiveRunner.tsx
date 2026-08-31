"use client";

import { useState } from "react";

import { CheckIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { StatusBadge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { userMessage } from "@/lib/errors";
import { adaptive, type AdaptiveSession, type AdaptiveStep } from "@/lib/adaptive";
import { difficultyLabel, TONE_CLASSES } from "@/lib/labels";

/**
 * One adaptive session, one question at a time.
 *
 * ⚠️ THE CEILING AND THE THRESHOLD ARE READ FROM THE PAYLOAD, NEVER DERIVED.
 * Mastery is measured at the highest difficulty THIS concept actually has for
 * THIS student, which the server computed from the bank; a browser that assumed
 * «hard» would draw a bar no action of theirs can reach in a concept whose
 * questions stop at medium — and re-deriving a server verdict in TypeScript is
 * the two-spellings defect that made a paid-for recording unreachable in 018.
 */
export function AdaptiveRunner({
  start,
  onFinish,
}: {
  start: { session: AdaptiveSession; question: AdaptiveStep["question"] };
  onFinish: () => void;
}) {
  const [session, setSession] = useState(start.session);
  const [question, setQuestion] = useState(start.question);
  const [selected, setSelected] = useState<number[]>([]);
  const [step, setStep] = useState<AdaptiveStep | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  /*
    One answer per question — selecting REPLACES, it never accumulates.

    ⚠️ THE SIBLING RUNNER USED TO ADD, AND THAT MADE EVERY SECOND TAP AN
    AUTOMATIC ZERO: grading compares the SETS and a question carries exactly one
    correct option, so a second tap turned a right answer into a wrong one with
    nothing on screen to say a second tap was not allowed. Tapping the chosen
    option again clears it, so «unanswered» stays reachable — an empty selection
    is marked wrong, which is what «I do not know» is.
  */
  const choose = (optionId: number) =>
    setSelected((previous) => (previous.includes(optionId) ? [] : [optionId]));

  const submit = async () => {
    if (question === null) return;

    setBusy(true);
    setError("");

    try {
      const next = (await adaptive.answer(session.uuid, question.question_id, selected)).data;
      setStep(next);
      setSession(next.session);
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setBusy(false);
    }
  };

  const advance = () => {
    if (step === null) return;

    setQuestion(step.question);
    setSelected([]);
    setStep(null);
  };

  const stop = async () => {
    setBusy(true);

    try {
      setSession((await adaptive.end(session.uuid)).data.session);
      onFinish();
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setBusy(false);
    }
  };

  const closed = session.status !== "running";

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-bold text-ink">{session.concept.name}</h2>
            <p className="mt-1 text-sm text-ink-muted">
              السؤال <bdi>{session.served_count}</bdi> من <bdi>{session.max_questions}</bdi> ·
              الصعوبة الآن: {difficultyLabel(session.difficulty)}
            </p>
            {/* FR-003: the bar is stated, because a bar nobody states is a bar
                nobody can aim at. */}
            <p className="mt-1 text-sm text-ink-muted">
              الإتقان: <bdi>{session.mastery_after}</bdi> إجابات صحيحة متتالية عند مستوى{" "}
              {difficultyLabel(session.ceiling_difficulty)} · متتالياتك الآن:{" "}
              <bdi>{session.correct_streak}</bdi>
            </p>
          </div>
          <StatusBadge status={session.status} />
        </div>
      </Card>

      {error !== "" && <Alert tone="danger" title={error} />}

      {/* FR-004 out loud. A level that moved without a word is a student handed
          a difficulty they cannot account for. */}
      {step?.difficulty_note != null && step.difficulty_note !== "" && (
        <Alert tone="info" title={step.difficulty_note} />
      )}

      {closed && (
        <Card>
          <h3 className="text-xl font-bold text-ink">
            {session.status === "mastered" ? "أتقنتَ هذه الفكرة 🎉" : "انتهت الجلسة"}
          </h3>
          <p className="mt-2 text-sm text-ink-muted">
            جلسةُ تدريب: لا تُحتسب في درجاتك ولا تستهلك محاولةً رسمية
            {session.score !== null && (
              <>
                {" "}
                · نتيجتك <bdi>{Math.round(session.score)}</bdi>٪
              </>
            )}
            .
          </p>
          <div className="mt-4">
            <Button onClick={onFinish}>اختر فكرةً أخرى</Button>
          </div>
        </Card>
      )}

      {!closed && question !== null && (
        <Card as="section">
          <div className="mb-4 flex items-start gap-3">
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-medium text-primary-ink">
              <bdi>{question.order}</bdi>
            </span>
            <p className="flex-1 font-medium text-ink">{question.content}</p>
          </div>

          <fieldset className="space-y-2" disabled={step !== null}>
            <legend className="sr-only">{question.content}</legend>
            {question.options.map((option) => {
              const chosen = selected.includes(option.id);
              const isRight = step?.result.correct_option_ids.includes(option.id) === true;

              return (
                <button
                  key={option.id}
                  type="button"
                  aria-pressed={chosen}
                  onClick={() => choose(option.id)}
                  className={`flex w-full items-center gap-3 rounded-lg border p-3 text-start text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                    /* After marking, the right answer is shown whether or not it
                       was chosen — being told «خطأ» without being told what was
                       right teaches nothing, which is FR-024's whole point. */
                    /* ⚠️ `TONE_CLASSES`, NOT A HAND-WRITTEN COLOUR. Tailwind v4
                       emits NO rule for a token `@theme` never defined, so a
                       class naming one paints nothing at all — silently. That has
                       shipped four times in this tree; the tone map is the one
                       place the palette is known to exist. */
                    step !== null && isRight
                      ? `border-transparent ${TONE_CLASSES.success}`
                      : chosen
                        ? "border-primary bg-primary-soft text-ink"
                        : "border-line text-ink hover:border-primary/40"
                  }`}
                >
                  <span
                    aria-hidden="true"
                    className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border ${
                      chosen ? "border-primary bg-primary text-white" : "border-line"
                    }`}
                  >
                    {chosen && <CheckIcon className="h-3 w-3" />}
                  </span>
                  {option.content}
                </button>
              );
            })}
          </fieldset>

          {step !== null && (
            <div className="mt-4 space-y-3">
              <Alert
                tone={step.result.is_correct ? "success" : "danger"}
                title={step.result.is_correct ? "إجابة صحيحة" : "إجابة غير صحيحة"}
              />
              {step.result.explanation !== null && step.result.explanation !== "" && (
                <p className="rounded-lg bg-primary-soft/40 p-3 text-sm text-ink">
                  {step.result.explanation}
                </p>
              )}
            </div>
          )}

          <div className="mt-4 flex flex-wrap gap-3">
            {step === null ? (
              <Button onClick={submit} disabled={busy}>
                تأكيد الإجابة
              </Button>
            ) : (
              step.question !== null && (
                <Button onClick={advance} disabled={busy}>
                  السؤال التالي
                </Button>
              )
            )}
            <Button variant="secondary" onClick={stop} disabled={busy}>
              إنهاء الجلسة
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}
