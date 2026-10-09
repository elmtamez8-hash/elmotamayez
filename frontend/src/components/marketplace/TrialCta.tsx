import Link from "next/link";

import { PlayIcon } from "@/components/icons";
import type { TrialLesson } from "@/lib/public-api";

/**
 * «حصة تجريبية» on the teacher's page — the teacher's free RECORDED lesson.
 *
 * ⚠️ OWNER DECISION 2026-10-09: the trial is a lesson already recorded, which
 * anyone may watch to see how this teacher explains before booking. Not a live
 * session, not paid, no approval. It used to send a guest to the student signup
 * form, which promised «you will return to the teacher's page to complete the
 * booking», and the teacher's page then told the new account «الحجز من لوحتك» —
 * a loop with no booking anywhere in it (owner audit 2026-10-09).
 *
 * The same link for everybody, signed in or not: the lesson door is public. So
 * this is a server component now, and it no longer flashes a guest label at a
 * signed-in reader for one frame.
 *
 * With no watchable lesson the control becomes the way to the teacher's courses
 * — where groups and private lessons are actually booked — never a dead button.
 */
export function TrialCta({
  trial,
  coursesHref,
  variant,
}: {
  /** `undefined` too: a payload cached before the key existed simply has none. */
  trial?: TrialLesson | null;
  /** The teacher page's «الكورسات» tab. */
  coursesHref: string;
  /** `profile` is the stacked panel; `bar` is the fixed strip that only exists below `lg`. */
  variant: "profile" | "bar";
}) {
  const className = {
    profile:
      "mb-3 flex items-center justify-center gap-2 rounded-full bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
    bar: "flex flex-1 items-center justify-center gap-2 rounded-full bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
  }[variant];

  if (trial == null) {
    return (
      <Link href={coursesHref} className={className}>
        تصفّح كورسات المدرّس
      </Link>
    );
  }

  return (
    <>
      <Link
        href={`/courses/${trial.course_slug}/lessons/${trial.lesson_uuid}`}
        className={className}
      >
        <PlayIcon className="h-5 w-5 shrink-0" />
        {variant === "bar" ? "حصة تجريبية مجانية" : "شاهد حصة تجريبية مجاناً"}
      </Link>
      {variant === "profile" && (
        <p className="mb-3 text-center text-xs leading-relaxed text-ink-muted">
          درس مسجَّل من شرح المدرّس: «{trial.title}». بلا حساب وبلا حجز.
        </p>
      )}
    </>
  );
}
