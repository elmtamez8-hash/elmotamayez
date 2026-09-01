import {
  AcademicCapIcon,
  ArabicIcon,
  BiologyIcon,
  ChemistryIcon,
  ComputerIcon,
  GeographyIcon,
  HistoryIcon,
  type IconProps,
  LanguageIcon,
  MathIcon,
  MosqueIcon,
  PhysicsIcon,
  ScienceIcon,
  SocialStudiesIcon,
  VocabularyIcon,
} from "@/components/icons";
import Link from "next/link";
import type { Taxonomy } from "@/lib/public-api";
import { EmptyState } from "@/components/ui/states/EmptyState";

/**
 * A glyph per subject. Every tile carried the same graduation cap, which is the
 * one thing thirteen tiles all mean already — so the icon said nothing and the
 * grid was read by its Arabic labels alone.
 *
 * Keyed by SLUG, because that is the stable identifier: `name_ar` is editable
 * from `/admin` and a rename would silently drop the subject back to the
 * fallback.
 */
const BY_SLUG: Record<string, (props: IconProps) => React.ReactElement> = {
  math: MathIcon,
  science: ScienceIcon,
  physics: PhysicsIcon,
  chemistry: ChemistryIcon,
  biology: BiologyIcon,
  arabic: ArabicIcon,
  english: LanguageIcon,
  french: VocabularyIcon,
  "islamic-studies": MosqueIcon,
  "social-studies": SocialStudiesIcon,
  history: HistoryIcon,
  geography: GeographyIcon,
  "computer-science": ComputerIcon,
};

/**
 * ⚠️ `subjects.icon` IS THE OPERATOR'S ONLY LEVER, and it travelled to this
 * component unread. A subject added from `/admin` tomorrow has a slug this file
 * has never heard of, so without this second map its tile keeps the graduation
 * cap whatever the operator types — a column shipped, seeded and decorative for
 * ever. The names are the ones `TaxonomySeeder` writes.
 */
const BY_ICON_NAME: Record<string, (props: IconProps) => React.ReactElement> = {
  calculator: MathIcon,
  beaker: ChemistryIcon,
  "book-open": VocabularyIcon,
  language: LanguageIcon,
  "computer-desktop": ComputerIcon,
};

function subjectIcon(subject: Taxonomy) {
  return (
    BY_SLUG[subject.slug] ??
    (subject.icon ? BY_ICON_NAME[subject.icon] : undefined) ??
    AcademicCapIcon
  );
}

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
