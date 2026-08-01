import Link from "next/link";
import type { Taxonomy } from "@/lib/public-api";
import { EmptyState } from "./states/EmptyState";

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
      {subjects.map((subject) => (
        <li key={subject.slug}>
          <Link
            href={`/teachers?subject=${subject.slug}`}
            className="flex h-full flex-col items-center gap-3 rounded-2xl border border-line bg-white p-5 text-center transition hover:border-primary hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:bg-transparent"
          >
            <span
              className="flex h-12 w-12 items-center justify-center rounded-full bg-primary-soft text-primary"
              aria-hidden="true"
            >
              <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M4.26 10.147a60.44 60.44 0 00-.491 6.347A48.62 48.62 0 0112 20.904a48.62 48.62 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.636 50.636 0 00-2.658-.813A59.906 59.906 0 0112 3.493a59.903 59.903 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0112 13.489a50.702 50.702 0 017.74-3.342"
                />
              </svg>
            </span>
            <span className="text-sm font-semibold text-ink">{subject.name_ar}</span>
            {subject.teachers_count !== undefined && (
              <span className="text-xs text-ink-muted">
                {subject.teachers_count} مدرّس
              </span>
            )}
          </Link>
        </li>
      ))}
    </ul>
  );
}
