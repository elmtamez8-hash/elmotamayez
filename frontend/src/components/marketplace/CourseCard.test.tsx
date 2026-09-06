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
  slug: "asasyat-altfadl",
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

    expect(hrefOf("أساسيّات التفاضل")).toBe(`/courses/${course.slug}`);
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

    expect(hrefOf("أساسيّات التفاضل")).toBe(`/courses/${course.slug}`);
  });

  /*
  | ⚠️ INVERTED, NOT DELETED (2026-09-06). This case read «addresses the course
  | by uuid, never by a slug», and its reason was sound: `courses.slug` was
  | unique per (workspace_id, slug), so a slug in a public path could not tell
  | two teachers' «الرياضيات ٣» apart. The index is platform-wide now — the key
  | `/teachers/{slug}` has carried since 2026-08 — so the sentence it guarded is
  | no longer true and the guard states the new rule instead of vanishing.
  |
  | What has NOT changed is the half that was never about the index: the course's
  | address is never the TEACHER's slug. That is still asserted below.
  */
  it("addresses the course by its own slug, never by the teacher's", () => {
    render(<CourseCard course={course} />);

    expect(hrefOf("أساسيّات التفاضل")).toBe("/courses/asasyat-altfadl");
    expect(hrefOf("أساسيّات التفاضل")).not.toContain("khaled");
  });
});

/*
| Spec 027 — the anchor is the inbound link the teacher's profile needed.
|
| ⚠️ THIS GUARDS A DESTINATION, NOT A PROP. The schedule tab is where a student
| reads a teacher's weekly times, and it offered no way to act on any of them:
| both subscription doors live on a course page, and nothing on that tab pointed
| at one. The fix is these cards plus `#groups`, so what fails here is the LINK
| going back to being a link to nowhere in particular — the same class of defect
| as the title pointing at the teacher, which is what opened 023.
|
| The default is asserted beside it because the marketplace listing and the
| profile's own courses tab render this card too, and a fragment leaking into
| those is a scroll a reader did not ask for.
*/
describe("CourseCard · the groups anchor", () => {
  it("lands on the course's groups when the caller asks for it", () => {
    render(<CourseCard course={course} anchor="#groups" />);

    expect(hrefOf("أساسيّات التفاضل")).toBe("/courses/asasyat-altfadl#groups");
  });

  it("carries no fragment when nobody asked for one", () => {
    render(<CourseCard course={course} />);

    expect(hrefOf("أساسيّات التفاضل")).toBe("/courses/asasyat-altfadl");
  });

  it("falls back to the uuid for a card rendered before slugs were sent", () => {
    /*
    | ⚠️ NOT DEFENSIVE DECORATION — THIS IS REAL TRAFFIC. Public listings are
    | ISR-cached, so a page rendered from a payload that predates 027's `slug`
    | key keeps serving until it revalidates. Without the fallback those cards
    | link to `/courses/undefined`, which 404s a listing that looked fine.
    */
    render(<CourseCard course={{ ...course, slug: null }} anchor="#groups" />);

    expect(hrefOf("أساسيّات التفاضل")).toBe(
      "/courses/c0ffee00-0000-4000-8000-000000000001#groups",
    );
  });

  it("never glues the fragment onto the teacher's link", () => {
    // `#groups` does not exist on a profile, so a byline carrying it is a
    // control that silently does nothing.
    render(<CourseCard course={course} anchor="#groups" />);

    expect(hrefOf("خالد")).toBe("/teachers/khaled");
  });
});
