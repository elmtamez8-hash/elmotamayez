import Link from "next/link";
import { ChevronEndIcon } from "@/components/icons";
import type { Taxonomy } from "@/lib/public-api";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { subjectIcon } from "./subject-icon";
import { counted } from "@/lib/labels";

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
    <ul className="grid grid-cols-2 gap-4 sm:gap-5 lg:grid-cols-4">
      {subjects.map((subject) => {
        const Icon = subjectIcon(subject);

        return (
          <li key={subject.slug} className="reveal">
            <Link
              href={`/teachers?subject=${subject.slug}`}
              className="group relative isolate flex h-full min-h-44 flex-col justify-between gap-6 overflow-hidden rounded-3xl border border-line bg-surface-raised p-5 transition duration-300 ease-out hover:-translate-y-1.5 hover:border-primary hover:bg-primary hover:shadow-xl hover:shadow-primary/20 active:translate-y-0 active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none sm:p-6"
            >
              {/* The subject's own glyph, oversized and faint in the corner —
                  the tile's colour comes from this one stroke, not from a
                  tinted fill on every tile (nine pale-pink discs once turned
                  this grid into a field of pink). On hover the whole tile
                  becomes the brand colour and the glyph follows. */}
              <Icon
                className="pointer-events-none absolute -bottom-6 -end-6 -z-10 h-32 w-32 rotate-12 text-primary-ink/10 transition duration-500 ease-out group-hover:-rotate-6 group-hover:scale-110 group-hover:text-white/15 motion-reduce:transition-none"
              />

              <span className="flex items-start justify-between">
                <span
                  className="grid h-14 w-14 place-items-center rounded-2xl bg-primary text-white shadow-lg shadow-primary/20 transition duration-300 ease-out group-hover:bg-accent group-hover:text-accent-foreground"
                  aria-hidden="true"
                >
                  <Icon className="h-7 w-7" />
                </span>
                <ChevronEndIcon className="h-5 w-5 translate-x-2 text-white opacity-0 transition duration-300 ease-out group-hover:translate-x-0 group-hover:opacity-100 motion-reduce:transition-none" />
              </span>

              <span>
                <span className="block text-lg font-extrabold text-ink transition-colors duration-300 group-hover:text-white sm:text-xl">
                  {subject.name}
                </span>
                {subject.teachers_count !== undefined && (
                  <span className="mt-1 block text-sm text-ink-muted transition-colors duration-300 group-hover:text-white/80">
                    {counted(subject.teachers_count, { one: "مدرّس واحد", two: "مدرّسان", few: "مدرّسين", many: "مدرّساً", other: "مدرّس" })}
                  </span>
                )}
              </span>
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
