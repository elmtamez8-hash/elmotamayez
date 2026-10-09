import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { TrialCta } from "./TrialCta";

/*
| Owner decision 2026-10-09 — «حصة تجريبية» is the teacher's free RECORDED lesson.
| The button opens it for everybody, and without one leads to the courses: never
| the signup form and never the panel (the loop the owner audit found).
*/
describe("TrialCta", () => {
  it("opens the teacher's free lesson", () => {
    render(
      <TrialCta
        trial={{ course_slug: "physics-3", lesson_uuid: "l-1", title: "الحركة في بعد واحد" }}
        coursesHref="/teachers/ahmed?tab=courses"
        variant="profile"
      />,
    );

    const link = screen.getByRole("link", { name: /شاهد حصة تجريبية مجاناً/ });

    expect(link.getAttribute("href")).toBe("/courses/physics-3/lessons/l-1");
    expect(screen.getByText(/الحركة في بعد واحد/)).toBeDefined();
  });

  it("leads to the courses when there is no free lesson — or no key at all in a cached payload", () => {
    for (const trial of [null, undefined]) {
      const { unmount } = render(
        <TrialCta trial={trial} coursesHref="/teachers/ahmed?tab=courses" variant="bar" />,
      );

      expect(screen.getByRole("link", { name: "تصفّح كورسات المدرّس" }).getAttribute("href")).toBe(
        "/teachers/ahmed?tab=courses",
      );
      expect(screen.queryByRole("link", { name: /signup|إنشاء حساب/ })).toBeNull();
      unmount();
    }
  });
});
