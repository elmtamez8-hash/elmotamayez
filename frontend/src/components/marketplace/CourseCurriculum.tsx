import { lessonTypeLabel } from "@/lib/labels";
import type { CurriculumSection } from "@/lib/public-api";

/**
 * The published tree, as a visitor who has not bought the course may read it.
 *
 * ⚠️ NOTHING HERE IS A LINK, AND THAT IS THE COMPONENT'S WHOLE JOB (FR-005 ·
 * SC-004). The payload carries no lesson identifier and no media path, so a link
 * cannot be built here even by mistake — the shape of the data is the guard, and
 * this file is where somebody would otherwise be tempted to add one.
 */
function duration(seconds: number | null): string | null {
  if (seconds === null || seconds <= 0) return null;

  const minutes = Math.round(seconds / 60);

  // Arabic counts in four bands and 11+ returns to the SINGULAR — «١٢ دقيقة»,
  // never «١٢ دقائق». The dual is its own word.
  if (minutes === 1) return "دقيقة";
  if (minutes === 2) return "دقيقتان";
  if (minutes <= 10) return `${minutes.toLocaleString("ar-QA")} دقائق`;

  return `${minutes.toLocaleString("ar-QA")} دقيقة`;
}

export function CourseCurriculum({
  sections,
}: {
  sections: CurriculumSection[];
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
                  {chapter.items.map((item, itemIndex) => (
                    <li
                      key={`${item.title}-${itemIndex}`}
                      className="flex items-baseline justify-between gap-3 px-5 py-2.5 text-sm"
                    >
                      <span className="flex min-w-0 items-baseline gap-2">
                        <span className="shrink-0 rounded bg-primary-soft px-1.5 py-0.5 text-[0.6875rem] font-medium text-primary-ink">
                          {lessonTypeLabel(item.kind)}
                        </span>
                        <span className="truncate text-ink">{item.title}</span>
                      </span>

                      {duration(item.duration_seconds) && (
                        <span className="shrink-0 text-xs text-ink-muted">
                          {duration(item.duration_seconds)}
                        </span>
                      )}
                    </li>
                  ))}
                </ol>
              </li>
            ))}
          </ol>
        </li>
      ))}
    </ol>
  );
}
