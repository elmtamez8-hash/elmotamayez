import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CourseCard } from "./CourseCard";
import type { CourseCard as Course } from "@/lib/public-api";

/*
| Spec 023 · SC-001 — the card opens the COURSE.
|
| ⚠️ THIS IS THE DEFECT THAT OPENED THE WHOLE SPEC, AND IT TYPECHECKED PERFECTLY.
| The title linked to `/teachers/{slug}?tab=courses`: a visitor who pressed
| «أساسيّات التفاضل» landed on a biography and had to find the same course again
| inside a tab — three clicks to reach the thing they had already named. Nothing
| failed, nothing warned, and no backend test can see it: the destination of a
| link is a fact about this file alone.
|
| So the assertion is on the `href`, not on the presence of a link. A card whose
| title links anywhere at all renders a link.
*/
const course: Course = {
  uuid: "c0ffee00-0000-4000-8000-000000000001",
  title: "أساسيّات التفاضل",
  cover_url: null,
  teacher: {
    uuid: "beef0000-0000-4000-8000-000000000002",
    slug: "khaled",
    name: "خالد",
    photo_url: null,
  },
  type: "group",
  lessons_count: 12,
  duration_seconds: 7200,
  average_rating: null,
  enrolled_count: 30,
  is_bestseller: false,
};

function hrefOf(text: string): string | null | undefined {
  return screen.getByText(text).closest("a")?.getAttribute("href");
}

describe("CourseCard", () => {
  it("sends the title to the course, not to the teacher", () => {
    render(<CourseCard course={course} />);

    expect(hrefOf("أساسيّات التفاضل")).toBe(`/courses/${course.uuid}`);
  });

  it("keeps the teacher byline as its own destination", () => {
    render(<CourseCard course={course} />);

    // Two destinations on one card, deliberately: the stretched title covers the
    // whole article, and the byline sits above it on the z-axis. If this ever
    // returns the course url, the byline has been swallowed by the overlay and
    // «who teaches this» is unreachable.
    expect(hrefOf("خالد")).toBe("/teachers/khaled");
  });

  it("still opens the course when the teacher cannot be published", () => {
    // The byline is null when the author has no public page — and the title used
    // to fall back to plain text in that case, so the card became unclickable
    // rather than merely unattributed.
    render(<CourseCard course={{ ...course, teacher: null }} />);

    expect(hrefOf("أساسيّات التفاضل")).toBe(`/courses/${course.uuid}`);
  });

  it("addresses the course by uuid, never by a slug", () => {
    render(<CourseCard course={course} />);

    // `courses.slug` is unique per (workspace_id, slug) — inside one workspace
    // only — so a slug in a public path cannot tell two teachers' «الرياضيات ٣»
    // apart. The card carries no slug at all, which is what keeps this true.
    expect(hrefOf("أساسيّات التفاضل")).not.toContain("khaled");
  });
});
