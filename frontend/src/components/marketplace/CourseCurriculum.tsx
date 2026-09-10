import Link from "next/link";

import { counted, lessonTypeLabel } from "@/lib/labels";
import type { CurriculumSection } from "@/lib/public-api";

/**
 * The published tree, as a visitor who has not bought the course may read it.
 *
 * ⚠️ THIS IS THE ONE PLACE A LESSON LINK IS MADE, AND ITS CONDITION IS THE
 * PAYLOAD'S SHAPE — NOT A RULE RESTATED HERE (023 · FR-005/SC-004, amended by
 * 032 · FR-019).
 *
 * The rule used to be «nothing here is a link», and the guard was that the
 * payload carried no identifier at all. It still carries none for every item
 * EXCEPT an open embedded lesson: that one has no media asset, so its uuid opens
 * nothing at the playback endpoint, which is why 032 publishes it and nothing
 * else. The guard is unchanged in kind — a link cannot be built for any other
 * item because there is no `uuid` on it to build one from.
 *
 * ⛔ DO NOT RE-DERIVE THE CONDITION HERE. Writing `kind === "embed" && …` in
 * TypeScript is a second spelling of `Lesson::isPubliclyReadable()`, and the two
 * drift: the version that made a paid-for recording unreachable in 018 was
 * exactly that. Read the key the server filled.
 */
function duration(seconds: number | null | undefined): string | null {
  if (seconds === null || seconds === undefined || seconds <= 0) return null;

  const minutes = Math.round(seconds / 60);

  return counted(minutes, {
    one: "دقيقة",
    two: "دقيقتان",
    few: "دقائق",
    many: "دقيقة",
    other: "دقيقة",
  });
}

export function CourseCurriculum({
  sections,
  courseSlug,
}: {
  sections: CurriculumSection[];
  /** Absent on a preview that has no page to link to yet. */
  courseSlug?: string;
}) {
  return (
    <ol className="flex flex-col gap-6">
      {sections.map((section, sectionIndex) => (
        <li
          key={`${section.title}-${sectionIndex}`}
          className="overflow-hidden rounded-2xl border border-line bg-surface-raised"
        >
          <h3 className="border-b border-line bg-primary-soft px-5 py-3 text-sm font-extrabold text-primary-ink">
            {section.title}
          </h3>

          <ol className="flex flex-col">
            {section.chapters.map((chapter, chapterIndex) => (
              <li key={`${chapter.title}-${chapterIndex}`}>
                <h4 className="px-5 pt-4 pb-1 text-xs font-bold text-ink-muted">
                  {chapter.title}
                </h4>

                <ol className="flex flex-col">
                  {chapter.items.map((item, itemIndex) => {
                    const openable =
                      item.is_open === true &&
                      item.uuid !== undefined &&
                      courseSlug !== undefined;

                    const label = (
                      <>
                        <span className="flex min-w-0 items-baseline gap-2">
                          <span className="shrink-0 rounded bg-primary-soft px-1.5 py-0.5 text-[0.6875rem] font-medium text-primary-ink">
                            {lessonTypeLabel(item.kind)}
                          </span>
                          <span className={`truncate ${openable ? "text-primary-ink underline" : "text-ink"}`}>
                            {item.title}
                          </span>
                          {openable && (
                            <span className="shrink-0 rounded bg-secondary/15 px-1.5 py-0.5 text-[0.6875rem] font-medium text-secondary-ink">
                              مجّانيّة
                            </span>
                          )}
                        </span>

                        {duration(item.duration_seconds) && (
                          <span className="shrink-0 text-xs text-ink-muted">
                            {duration(item.duration_seconds)}
                          </span>
                        )}
                      </>
                    );

                    return (
                      <li key={`${item.title}-${itemIndex}`}>
                        {openable ? (
                          <Link
                            href={`/courses/${courseSlug}/lessons/${item.uuid}`}
                            className="flex items-baseline justify-between gap-3 px-5 py-2.5 text-sm hover:bg-primary-soft/40"
                          >
                            {label}
                          </Link>
                        ) : (
                          <span className="flex items-baseline justify-between gap-3 px-5 py-2.5 text-sm">
                            {label}
                          </span>
                        )}
                      </li>
                    );
                  })}
                </ol>
              </li>
            ))}
          </ol>
        </li>
      ))}
    </ol>
  );
}
