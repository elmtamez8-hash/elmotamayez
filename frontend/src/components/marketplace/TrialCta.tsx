import Link from "next/link";

import { ChevronDownIcon, PlayIcon, SessionsIcon } from "@/components/icons";
import type { TeacherTrial } from "@/lib/public-api";

/**
 * The teacher page's main button — spec 040, owner decisions 2026-10-09.
 *
 * «حصة تجريبية» is a recorded lesson the teacher chose, ONE PER COURSE: a teacher
 * teaches several subjects and grades, so one sample cannot judge them all. In
 * order:
 * 1. One course with a trial → straight to it.
 * 2. Several → the list, each named by its course and subject, to pick from.
 * 3. None, but a working intro video → «شاهد فيديو المدرّس», to the video on the
 *    page (from any tab: the link carries `?tab=about`).
 * 4. Neither → the teacher's courses, where groups and private lessons are booked.
 *
 * Never the signup form or the panel: that was the #375 loop (signup promised a
 * booking the teacher page then refused to make).
 *
 * A server component: the same links for everybody, signed in or not.
 */
export function TrialCta({
  trials,
  hasIntroVideo,
  teacherHref,
  variant,
}: {
  /** `undefined` too: a payload cached before the key existed simply has none. */
  trials?: TeacherTrial[];
  /** Decided by `videoEmbedUrl(intro_video_url) !== null`, the page's own test. */
  hasIntroVideo: boolean;
  /** `/teachers/{slug}` — the base for the tab links. */
  teacherHref: string;
  /** `profile` is the stacked panel; `bar` is the fixed strip below `lg`. */
  variant: "profile" | "bar";
}) {
  const filled = {
    profile:
      "mb-3 flex items-center justify-center gap-2 rounded-full bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
    bar: "flex flex-1 items-center justify-center gap-2 rounded-full bg-accent px-5 py-3 text-center text-base font-semibold text-accent-foreground transition duration-200 ease-out hover:brightness-105 active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
  }[variant];

  const list = trials ?? [];

  if (list.length === 1) {
    const [trial] = list;

    return (
      <>
        <Link href={`/courses/${trial.course_slug}/trial`} className={filled}>
          <PlayIcon className="h-5 w-5 shrink-0" />
          {variant === "bar" ? "حصة تجريبية مجانية" : "شاهد حصة تجريبية مجاناً"}
        </Link>
        {variant === "profile" && (
          <p className="mb-3 text-center text-xs leading-relaxed text-ink-muted">
            «{trial.lesson_title}» من {trial.course_title}. بلا حساب وبلا حجز.
          </p>
        )}
      </>
    );
  }

  if (list.length > 1) {
    // The strip has room for one control: it leads to the courses, whose cards
    // carry the trial badge. The panel shows the list itself.
    if (variant === "bar") {
      return (
        <Link href={`${teacherHref}?tab=courses`} className={filled}>
          <PlayIcon className="h-5 w-5 shrink-0" />
          حصص تجريبية مجانية
        </Link>
      );
    }

    return (
      <details className="group mb-3">
        <summary className={`${filled} mb-0 cursor-pointer list-none [&::-webkit-details-marker]:hidden`}>
          <PlayIcon className="h-5 w-5 shrink-0" />
          شاهد حصة تجريبية مجاناً
          <ChevronDownIcon className="h-4 w-4 shrink-0 transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" />
        </summary>
        <ul className="mt-3 space-y-2">
          {list.map((trial) => (
            <li key={trial.course_slug}>
              <Link
                href={`/courses/${trial.course_slug}/trial`}
                className="group/trial flex items-center gap-3 rounded-2xl border border-line p-3 text-start transition duration-200 hover:border-primary/40 hover:bg-primary-soft/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                <span
                  aria-hidden="true"
                  className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink transition duration-200 group-hover/trial:bg-primary group-hover/trial:text-white"
                >
                  <PlayIcon className="h-4 w-4" />
                </span>
                <span className="min-w-0">
                  <span className="block truncate text-sm font-bold text-ink">{trial.course_title}</span>
                  <span className="block truncate text-xs text-ink-muted">
                    {trial.subject === null ? trial.lesson_title : `${trial.subject} · ${trial.lesson_title}`}
                  </span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </details>
    );
  }

  if (hasIntroVideo) {
    return (
      <Link href={`${teacherHref}?tab=about#intro-video`} className={filled}>
        <SessionsIcon className="h-5 w-5 shrink-0" />
        شاهد فيديو المدرّس
      </Link>
    );
  }

  return (
    <Link href={`${teacherHref}?tab=courses`} className={filled}>
      تصفّح كورسات المدرّس
    </Link>
  );
}
