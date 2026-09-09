import Link from "next/link";
import type { Taxonomy } from "@/lib/public-api";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { subjectIcon } from "./subject-icon";

/**
 * Clicking a subject lands on the teacher list with that filter pre-applied
 * (FR-043), so each tile is a real Link — not a click handler — and works with
 * middle-click, keyboard, and no JavaScript at all.
 */
export function SubjectsGrid({ subjects }: { subjects: Taxonomy[] }) {
  if (subjects.length === 0) {
    return (
      <EmptyState
        title="لا توجد مواد متاحة بعد"
        description="نعمل حالياً على انضمام أول دفعة من المدرّسين. عد قريباً."
      />
    );
  }

  return (
    <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
      {subjects.map((subject) => {
        const Icon = subjectIcon(subject);

        return (
          <li key={subject.slug}>
            <Link
              href={`/teachers?subject=${subject.slug}`}
              className="group flex h-full flex-col items-center gap-3 rounded-3xl border border-line bg-surface-raised p-5 text-center transition duration-200 ease-out hover:-translate-y-1 hover:border-primary hover:shadow-md active:translate-y-0 active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              {/* Outlined, not filled. Nine tiles each carrying a `bg-primary-soft`
                disc turned the largest grid on the page into a field of pale
                pink, and a maroon that appears everywhere stops reading as the
                brand colour and starts reading as the background. The maroon
                stays — on the glyph, where it is one stroke wide. */}
              <span
                className="flex h-12 w-12 items-center justify-center rounded-full border border-line bg-surface text-primary-ink transition duration-200 ease-out group-hover:border-primary group-hover:bg-primary group-hover:text-white"
                aria-hidden="true"
              >
                <Icon className="h-6 w-6" />
              </span>
              <span className="text-sm font-semibold text-ink">
                {subject.name_ar}
              </span>
              {subject.teachers_count !== undefined && (
                <span className="text-xs text-ink-muted">
                  {subject.teachers_count} مدرّس
                </span>
              )}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
