/**
 * The exam screen's clock and its saved paper (owner decision 2026-10-29: the
 * SERVER owns the clock, and closes a sitting on the answers it saved).
 *
 * Pure, so the arithmetic is tested without a browser.
 */

export type ExamQuestion = { id: number; type: string };

export type AnswerRow = {
  question_id: number;
  selected_option_ids: number[];
  answer_text?: string | null;
};

/**
 * Every question on the paper, answered or not — the shape both the save and
 * the submit send, so the server marks a blank as a blank rather than a gap.
 */
export function payloadOf(
  questions: ExamQuestion[],
  answers: Record<number, number[]>,
  texts: Record<number, string>,
): AnswerRow[] {
  return questions.map((q) =>
    q.type === "essay"
      ? {
          question_id: q.id,
          selected_option_ids: [],
          answer_text: (texts[q.id] ?? "").trim() === "" ? null : texts[q.id],
        }
      : { question_id: q.id, selected_option_ids: answers[q.id] ?? [] },
  );
}

/** The saved rows back into the screen's two maps, for a reload. */
export function restoreFrom(rows: AnswerRow[] | null | undefined): {
  answers: Record<number, number[]>;
  texts: Record<number, string>;
} {
  const answers: Record<number, number[]> = {};
  const texts: Record<number, string> = {};

  for (const row of rows ?? []) {
    answers[row.question_id] = row.selected_option_ids ?? [];
    if (row.answer_text) texts[row.question_id] = row.answer_text;
  }

  return { answers, texts };
}

/** Whole seconds left until the server's deadline, never below zero; null when untimed. */
export function secondsLeft(deadlineIso: string | null | undefined, nowMs: number): number | null {
  if (!deadlineIso) return null;

  return Math.max(0, Math.ceil((Date.parse(deadlineIso) - nowMs) / 1000));
}

/** `mm:ss` (or `h:mm:ss`) in Latin digits — the platform's digits (owner decision 2026-10-29). */
export function formatClock(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  const pad = (n: number) => String(n).padStart(2, "0");

  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
}
