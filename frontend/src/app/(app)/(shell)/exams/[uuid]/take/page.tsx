"use client";

import { use, useCallback, useEffect, useRef, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { counted, NOUNS } from "@/lib/labels";
import { useRouter } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckIcon, ExamIcon } from "@/components/icons";
import { PageHeader } from "@/components/ui/PageHeader";
import { TextareaField } from "@/components/ui/Field";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { arabicNumber } from "@/lib/numerals";
import { type AnswerRow, formatClock, localDeadline, payloadOf, restoreFrom, secondsLeft } from "@/lib/exam-clock";

interface AttemptResponse {
  attempt: {
    uuid: string;
    status: string;
    /** The server's deadline — it closes the sitting there, whatever this screen does. */
    deadline_at?: string | null;
    /** Seconds left, measured on the SERVER — the countdown starts from this. */
    seconds_left?: number | null;
    /** The answers saved so far, so a reload resumes the paper as it was. */
    draft_answers?: AnswerRow[] | null;
  };
  questions: Array<{
    id: number;
    type: string;
    content: string;
    points: number;
    options: Array<{ id: number; content: string }>;
  }>;
}

/** An essay carries no options; the teacher marks it by hand after submission. */
function isEssay(q: AttemptResponse["questions"][number]): boolean {
  return q.type === "essay";
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
  // An essay is answered in WORDS, and it used to have nowhere to put them: the
  // screen drew option buttons only, so an essay rendered as a question with no
  // control under it and was always submitted blank.
  const [texts, setTexts] = useState<Record<number, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [started, setStarted] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  // The deadline on this device's clock, fixed when the server's answer lands.
  const [deadlineMs, setDeadlineMs] = useState<number | null>(null);
  const [saveState, setSaveState] = useState<"idle" | "saving" | "saved" | "retrying">("idle");
  // Once a submit has gone out (by the student or by the clock) nothing else
  // may start one — the clock and the button must not both fire.
  const submittedRef = useRef(false);
  // The first render after a restore is the saved paper itself, not an edit.
  const restoredRef = useRef(true);

  /*
    ⛔ STARTED BY A PRESS, NEVER BY OPENING THE PAGE (security scan 2026-10-10,
    F3). The POST used to run on mount, so any link to this url — sent by a
    classmate, or opened in a hidden frame — spent one of the student's graded
    attempts without them ever seeing the paper.
  */
  const start = () => {
    setStarted(true);
    setLoading(true);
    setError("");
    api
      .post<AttemptResponse>(`/exams/${uuid}/attempts`)
      .then((res) => {
        setDeadlineMs(localDeadline(res.attempt.seconds_left, Date.now()));
        setData(res);
        // A reload resumes the paper as the server saved it.
        const restored = restoreFrom(res.attempt.draft_answers);
        const initial: Record<number, number[]> = {};
        res.questions.forEach((q) => {
          initial[q.id] = restored.answers[q.id] ?? [];
        });
        restoredRef.current = true;
        setAnswers(initial);
        setTexts(restored.texts);
      })
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  };

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

  const submit = useCallback(async () => {
    if (!data || submittedRef.current) return;
    submittedRef.current = true;
    setSubmitting(true);
    setError("");

    try {
      // Unanswered questions are submitted with an empty selection and graded
      // as zero — the exam total never shrinks to what was answered.
      const payload = payloadOf(data.questions, answers, texts);

      const result = await api.post<{ uuid: string }>(
        `/attempts/${data.attempt.uuid}/submit`,
        { answers: payload },
      );
      router.push(`/exams/${result.uuid}/result`);
    } catch (err: unknown) {
      submittedRef.current = false;
      setError(userMessage(err));
    } finally {
      setSubmitting(false);
    }
  }, [answers, data, router, texts]);

  /*
    ⛔ EVERY ANSWER IS SAVED THE MOMENT IT IS CHOSEN (owner decision 2026-10-29).
    The server closes a timed sitting on what it saved, so an answer that lived
    only on this screen when the clock ran out would be marked blank. A short
    pause gathers typing into one save; a failed save is retried until it lands
    or the sitting closes — and a 409 means the server already closed it, so the
    screen submits at once (it is marked on the saves, not on this payload).
  */
  useEffect(() => {
    if (!data || submittedRef.current) return;
    if (restoredRef.current) {
      restoredRef.current = false;
      return;
    }

    let cancelled = false;
    let retry: ReturnType<typeof setTimeout> | undefined;

    const save = () => {
      setSaveState("saving");
      api
        .put(`/attempts/${data.attempt.uuid}/answers`, { answers: payloadOf(data.questions, answers, texts) })
        .then(() => !cancelled && setSaveState("saved"))
        .catch((err: unknown) => {
          if (cancelled) return;
          if (err instanceof ApiError && err.status === 409) {
            void submit();
            return;
          }
          setSaveState("retrying");
          retry = setTimeout(save, 3000);
        });
    };

    const pause = setTimeout(save, 400);

    return () => {
      cancelled = true;
      clearTimeout(pause);
      if (retry !== undefined) clearTimeout(retry);
    };
  }, [answers, data, submit, texts]);

  /*
    And when the tab is closed or hidden mid-pause, the last answer still goes:
    `putKeepalive` outlives the page, so a choice made a second before closing
    is on the server when the clock closes the sitting.
  */
  const latest = useRef<{ uuid: string; rows: ReturnType<typeof payloadOf> } | null>(null);
  latest.current = data ? { uuid: data.attempt.uuid, rows: payloadOf(data.questions, answers, texts) } : null;

  useEffect(() => {
    const flush = () => {
      if (latest.current && !submittedRef.current) {
        void api.putKeepalive(`/attempts/${latest.current.uuid}/answers`, { answers: latest.current.rows }).catch(() => undefined);
      }
    };
    window.addEventListener("pagehide", flush);

    return () => window.removeEventListener("pagehide", flush);
  }, []);

  // The countdown, to the SERVER's deadline. At zero the screen hands in what is
  // on it — inside the server's minute of grace, so it is marked as sent.
  const left = secondsLeft(deadlineMs, now);

  useEffect(() => {
    if (deadlineMs === null) return;
    const tick = setInterval(() => setNow(Date.now()), 1000);

    return () => clearInterval(tick);
  }, [deadlineMs]);

  useEffect(() => {
    if (left === 0) void submit();
  }, [left, submit]);

  if (!started) {
    return (
      <div className="space-y-6">
        <PageHeader
          Icon={ExamIcon}
          title="بدء الاختبار"
          description="عند الضغط على «ابدأ الاختبار» تبدأ محاولة جديدة وتُحتسب من عدد محاولاتك."
        />
        <div className="flex flex-wrap gap-3">
          <Button size="lg" onClick={start}>
            ابدأ الاختبار
          </Button>
          <Button href="/exams" variant="secondary" size="lg">
            عُد إلى الاختبارات
          </Button>
        </div>
      </div>
    );
  }

  if (loading) return <RowsSkeleton count={4} />;

  if (!data) {
    return (
      <div>
        <Alert tone="danger" title={error || "تعذّر بدء الاختبار."}>
          <Button href="/exams" variant="secondary" size="sm">
            عُد إلى الاختبارات
          </Button>
        </Alert>
      </div>
    );
  }

  const answered = data.questions.filter((q) =>
    isEssay(q) ? (texts[q.id] ?? "").trim() !== "" : (answers[q.id] ?? []).length > 0,
  ).length;

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={ExamIcon}
        title="اختبار جارٍ"
        description={
          <>
            أجب عن الأسئلة ثم سلّم. أجبت عن <bdi>{arabicNumber(answered)}</bdi> من{" "}
            <bdi>{arabicNumber(data.questions.length)}</bdi>.
          </>
        }
      />

      {left !== null && (
        <div
          role="timer"
          aria-live="off"
          className={`sticky top-2 z-10 flex items-center justify-between rounded-2xl border px-4 py-3 text-sm shadow-sm ${
            left <= 60 ? "border-danger/40 bg-danger/10 text-danger-ink" : "border-line bg-surface text-ink"
          }`}
        >
          <span>
            الوقت المتبقي: <bdi className="font-bold tabular-nums">{formatClock(left)}</bdi>
          </span>
          <span className="text-xs text-ink-muted">
            {saveState === "saving"
              ? "جارٍ حفظ إجاباتك…"
              : saveState === "retrying"
                ? "لم تُحفَظ آخر إجابة بعد — نعيد المحاولة."
                : saveState === "saved"
                  ? "حُفظت إجاباتك."
                  : "تُحفَظ إجاباتك تلقائياً، ويُسلَّم الاختبار عند انتهاء الوقت."}
          </span>
        </div>
      )}

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
                <p className="mt-1 text-xs text-ink-muted">{counted(q.points, NOUNS.points)}</p>
              </div>
            </div>

            {isEssay(q) ? (
              <TextareaField
                id={`essay-${q.id}`}
                label="إجابتك"
                rows={6}
                value={texts[q.id] ?? ""}
                onChange={(value) => setTexts((prev) => ({ ...prev, [q.id]: value }))}
                hint="يصحّحها المدرّس بعد التسليم."
              />
            ) : (
            /* A fieldset, not a bare div: the question text is the group's
               label, which is how a screen reader ties the options to it. */
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
            )}
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
