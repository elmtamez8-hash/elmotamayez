import Link from "next/link";

import { CheckIcon, ChevronStartIcon, ClockIcon, LockIcon } from "@/components/icons";
import { lockMessage, type CurriculumLesson } from "@/lib/curriculum";

/**
 * One item of the curriculum.
 *
 * ⚠️ A LOCKED ROW IS NEITHER A `<Link>` NOR A `<button>` (FR-007). That is the
 * whole defect this screen exists to fix: today a student discovers a lock by
 * tapping the row, waiting for a page, and reading a refusal on something they
 * cannot use. Rendering it as a `<div>` is not decoration — a disabled-looking
 * anchor is still an anchor to a keyboard, to a screen reader, and to a
 * long-press "open in new tab".
 *
 * ⚠️ AND THE STATE IS CARRIED BY A SHAPE AND A WORD, NOT BY A COLOUR (FR-005).
 * Every row has a mark and a text label a screen reader reaches; the colour is
 * emphasis on top of that. This repository has shipped an invisible state three
 * times by naming a colour token `@theme` never defined — Tailwind v4 emits no
 * rule at all for one, silently — and each time the test that should have caught
 * it was asserting on an `aria-label` over an unpainted mark.
 */

function minutes(seconds: number): string {
  return seconds > 0 ? `${Math.max(1, Math.round(seconds / 60))} دقيقة` : "";
}

/** The mark and the word beside it, per state. */
const MARKS = {
  completed: { label: "مكتمل", tone: "bg-secondary/15 text-secondary-ink" },
  open: { label: "متاح", tone: "bg-primary-soft text-primary-ink" },
  locked: { label: "مقفول", tone: "bg-line text-ink-muted" },
} as const;

export function LessonRow({ lesson }: { lesson: CurriculumLesson }) {
  const mark = MARKS[lesson.state];
  const duration = minutes(lesson.duration_seconds);

  const body = (
    <>
      <span
        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${mark.tone}`}
        aria-hidden="true"
      >
        {lesson.state === "completed" ? (
          <CheckIcon />
        ) : lesson.state === "locked" ? (
          <LockIcon />
        ) : (
          <ChevronStartIcon />
        )}
      </span>

      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="font-medium text-ink">{lesson.title}</span>
          {/* The word a colour cannot carry. `sr-only` would hide it from
              everyone who reads the screen rather than hears it — and the three
              states are exactly what this row is for. */}
          <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${mark.tone}`}>
            {mark.label}
          </span>
        </span>

        <span className="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-ink-muted">
          <span>{lesson.type_label}</span>
          {duration !== "" && (
            <span className="inline-flex items-center gap-1">
              <ClockIcon />
              <bdi>{duration}</bdi>
            </span>
          )}
        </span>

        {/*
          ⚠️ THE REASON IS IN THE ROW, NOT BEHIND A TAP. A lock with nothing after
          it is a support ticket — and an exam gate is invisible from the locked
          item, because what has to happen is on a different page entirely.
        */}
        {lesson.lock !== null && (
          <span className="mt-1.5 block text-xs leading-relaxed text-ink-muted">
            {lockMessage(lesson.lock)}
          </span>
        )}
      </span>
    </>
  );

  /*
    ⚠️ THE LOCK DECIDES WHETHER IT OPENS, NOT THE STATE — and the two can
    disagree. A FINISHED item is refused on an expired enrolment, and a reorder
    can put one behind unfinished work: the state stays `completed`, and the row
    still must not be a link, or the student taps «مكتمل» and the door refuses.
    That is the same two-answers defect the whole screen exists to end.
  */
  if (lesson.lock !== null) {
    return (
      // `bg-surface` — the page ground, under a card painted `surface-raised`,
      // so the row reads as recessed. There is no `surface-muted` token, and a
      // class naming one Tailwind v4 has never seen paints NOTHING at all,
      // silently: three states have shipped invisible in this tree that way.
      <div className="flex items-start gap-3 rounded-lg border border-line bg-surface p-3 text-sm">
        {body}
      </div>
    );
  }

  return (
    <Link
      href={`/learn/${lesson.uuid}`}
      className="flex items-start gap-3 rounded-lg border border-line p-3 text-sm transition hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
    >
      {body}
    </Link>
  );
}
