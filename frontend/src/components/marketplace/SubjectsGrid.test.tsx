import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { SubjectsGrid } from "./SubjectsGrid";
import type { Taxonomy } from "@/lib/public-api";

/*
| THE ICON IS THE ONLY THING ON A TILE THAT IS NOT THE LABEL, AND FOR THIRTEEN
| SUBJECTS IT WAS THE SAME GRADUATION CAP — a glyph that says «this is a school
| subject» beside thirteen tiles that all are one, i.e. nothing at all.
|
| ⚠️ THIS IS THE DEFECT FAMILY THAT SHIPS SILENTLY. A wrong or missing icon
| throws nothing, logs nothing and typechecks: the two ways it comes back are a
| renamed slug (`math` → `mathematics` in a seeder) and a subject added from
| `/admin` that no `BY_SLUG` key knows. The first is measured per slug below; the
| second is the whole point of the two fallback cases — a tile that keeps the cap
| whatever the operator typed is `subjects.icon` shipped, seeded and decorative
| for ever, which is what it already was until this component read it.
|
| Asserting on the Tabler class rather than on a snapshot: a snapshot goes red on
| a padding change and teaches the next reader to re-record it, at which point a
| swapped icon is re-recorded with it.
*/
function iconOf(name: string): string | undefined {
  const tile = screen.getByText(name).closest("a");
  const svg = tile?.querySelector("svg");

  return Array.from(svg?.classList ?? [])
    .find((c) => c.startsWith("tabler-icon-"))
    ?.replace("tabler-icon-", "");
}

const subject = (slug: string, name_ar: string, icon?: string): Taxonomy => ({
  slug,
  name_ar,
  icon,
});

describe("SubjectsGrid", () => {
  it("draws a distinct glyph for every seeded subject", () => {
    const subjects: Array<[Taxonomy, string]> = [
      [subject("math", "الرياضيات"), "math"],
      [subject("science", "العلوم"), "microscope"],
      [subject("physics", "الفيزياء"), "atom"],
      [subject("chemistry", "الكيمياء"), "flask"],
      [subject("biology", "الأحياء"), "dna"],
      [subject("arabic", "اللغة العربية"), "feather"],
      [subject("english", "اللغة الإنجليزية"), "language"],
      [subject("french", "اللغة الفرنسية"), "vocabulary"],
      [subject("islamic-studies", "التربية الإسلامية"), "building-mosque"],
      [subject("social-studies", "الاجتماعيات"), "users-group"],
      [subject("history", "التاريخ"), "hourglass"],
      [subject("geography", "الجغرافيا"), "world"],
      [subject("computer-science", "الحاسب الآلي"), "device-desktop"],
    ];

    render(<SubjectsGrid subjects={subjects.map(([s]) => s)} />);

    for (const [s, expected] of subjects) {
      expect(iconOf(s.name_ar), s.slug).toBe(expected);
    }

    // The grid is thirteen tiles wearing eleven glyphs, not one worn thirteen
    // times — the state this replaced, and the one a bad merge restores.
    expect(new Set(subjects.map(([s]) => iconOf(s.name_ar))).size).toBeGreaterThan(10);
  });

  it("falls back to the icon column for a slug it has never heard of", () => {
    render(<SubjectsGrid subjects={[subject("psychology", "علم النفس", "book-open")]} />);

    expect(iconOf("علم النفس")).toBe("vocabulary");
  });

  it("falls back to the graduation cap when there is nothing else to go on", () => {
    render(<SubjectsGrid subjects={[subject("astronomy", "الفلك")]} />);

    expect(iconOf("الفلك")).toBe("school");
  });
});
