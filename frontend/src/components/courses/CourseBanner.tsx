import type { ReactNode } from "react";

/**
 * The masthead of one course: its cover, its title, its teacher.
 *
 * ⚠️ A PLAIN `<img>`, NEVER `next/image`, AND THE REASON IS RECORDED IN
 * `CLAUDE.md`. The `sharp` advisory is accepted rather than fixed — it only
 * moves with Next 16 — and what keeps that acceptance honest is CALL-SITE
 * DISCIPLINE: every `next/image` in this tree passes a literal `/public` path,
 * so no user-supplied image ever reaches the optimiser. A course cover is
 * uploaded by a teacher. Passing it through `next/image` is exactly the trigger
 * that makes the advisory live again. Same reason `MessageList`, `ReviewsTab`
 * and `ParticipantsPanel` each use a plain `<img>` with a comment saying so.
 *
 * ⚠️ THE SCRIM IS COPIED FROM `PageBanner`, NOT INVENTED. Text over a photograph
 * has no contrast guarantee at all: the same white heading is 12:1 over a dark
 * corner and 1.4:1 over a bright one, and which corner it lands on depends on
 * the crop. Two layers, because the colour and the legibility are separate jobs
 * — a neutral black scrim does the contrast work and the tone is a wash on top
 * of it. A per-page overlay is a per-page contrast bug.
 *
 * A course with no cover is the ordinary case, not an error: the tone alone
 * fills the frame and the words sit on it at full contrast.
 */

const TONE = "from-primary/55 via-primary/45 via-55% to-transparent to-85%";
const SCRIM = "from-black/50 via-black/45 via-55% to-transparent to-85%";

export function CourseBanner({
  title,
  teacherName,
  coverUrl,
  children,
}: {
  title: string;
  teacherName: string | null;
  coverUrl: string | null;
  /** The progress bar, the counts, «تابعْ من هنا». */
  children?: ReactNode;
}) {
  return (
    <section className="relative flex min-h-48 items-end overflow-hidden rounded-3xl bg-primary sm:min-h-56">
      {coverUrl !== null && (
        <img
          src={coverUrl}
          // Empty on purpose: the cover is atmosphere behind a heading that
          // already says what the course is. Describing it twice is noise in a
          // screen reader, not access.
          alt=""
          className="absolute inset-0 h-full w-full object-cover"
        />
      )}

      <div className={`absolute inset-0 bg-gradient-to-t ${SCRIM}`} aria-hidden="true" />
      <div className={`absolute inset-0 bg-gradient-to-t ${TONE}`} aria-hidden="true" />

      {/* One authored arrival, staggered: name then byline then the numbers —
          the order the page is read in. `both` rather than `forwards` so the
          fill mode covers the delay too, or a staggered element is visible,
          vanishes when its animation starts, and reappears. */}
      <div className="relative w-full p-5 sm:p-8">
        <h1 className="banner-rise text-2xl font-extrabold text-white sm:text-3xl">{title}</h1>

        {teacherName !== null && (
          <p
            className="banner-rise mt-1 text-sm text-white/85"
            style={{ animationDelay: "90ms" }}
          >
            {teacherName}
          </p>
        )}

        {children !== undefined && (
          <div className="banner-rise mt-4" style={{ animationDelay: "180ms" }}>
            {children}
          </div>
        )}
      </div>
    </section>
  );
}
