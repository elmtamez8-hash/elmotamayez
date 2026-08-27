import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { LessonRow } from "@/components/courses/LessonRow";
import type { CurriculumSection } from "@/lib/curriculum";

/**
 * The course, as a syllabus: sections, then chapters, then items under each
 * other in the order the teacher put them in (FR-001).
 *
 * ⚠️ THE STAGGER IS CAPPED, AND THE CAP IS THE POINT. `banner-rise` with a delay
 * per row is fine for eight rows and unusable for two hundred: the last item of
 * a real course would arrive eighteen seconds after the first, which is a page
 * that appears broken rather than one that appears alive. The delay is per
 * SECTION and stops after the sixth — everything below the fold is already
 * scrolled to by the time it matters.
 *
 * `both` rather than `forwards`, so the fill mode covers the delay too: without
 * it an element is painted, vanishes when its animation starts, and reappears.
 * `prefers-reduced-motion` in `globals.css` zeroes all of it for free, which a
 * requestAnimationFrame version would not get.
 */

/** After this many, everything arrives together. */
const STAGGER_LIMIT = 6;

export function CurriculumTree({ sections }: { sections: CurriculumSection[] }) {
  const empty = sections.every((section) =>
    section.chapters.every((chapter) => chapter.lessons.length === 0),
  );

  if (sections.length === 0 || empty) {
    return (
      <EmptyState
        title="لا دروس منشورة بعد"
        description="سيظهر محتوى المادّة هنا فور نشر المدرّس أوّل درس."
      />
    );
  }

  return (
    <div className="space-y-4">
      {sections.map((section, index) => (
        <div
          key={section.uuid}
          className="banner-rise"
          style={{ animationDelay: `${Math.min(index, STAGGER_LIMIT) * 70}ms` }}
        >
          <Card>
            <h3 className="mb-3 font-semibold text-ink">{section.title}</h3>

            {section.chapters.map((chapter) => (
              <div key={chapter.uuid} className="mb-4 last:mb-0">
                <p className="mb-2 text-sm text-ink-muted">{chapter.title}</p>

                <ul className="space-y-2">
                  {chapter.lessons.map((lesson) => (
                    <li key={lesson.uuid}>
                      <LessonRow lesson={lesson} />
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </Card>
        </div>
      ))}
    </div>
  );
}
