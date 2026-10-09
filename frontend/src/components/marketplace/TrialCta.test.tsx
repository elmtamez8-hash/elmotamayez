import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { TeacherTrial } from "@/lib/public-api";
import { TrialCta } from "./TrialCta";

/*
| Spec 040 — the teacher page's main button, in order: one trial → it; several →
| the list; none but an intro video → the video; neither → the courses. Never the
| signup form and never the panel (the #375 loop).
*/

const TEACHER = "/teachers/ahmed";

function trial(over: Partial<TeacherTrial> = {}): TeacherTrial {
  return {
    course_slug: "physics-2",
    course_title: "الفيزياء ٢ ثانوي",
    subject: "الفيزياء",
    grade_level: "secondary",
    lesson_title: "الحركة في بعد واحد",
    kind: "video",
    ...over,
  };
}

describe("TrialCta", () => {
  it("opens the one trial directly, and names it", () => {
    render(<TrialCta trials={[trial()]} hasIntroVideo teacherHref={TEACHER} variant="profile" />);

    expect(screen.getByRole("link", { name: /شاهد حصة تجريبية مجاناً/ }).getAttribute("href")).toBe("/courses/physics-2/trial");
    expect(screen.getByText(/«الحركة في بعد واحد» من الفيزياء ٢ ثانوي/)).toBeDefined();
  });

  it("lists several trials by course and subject", () => {
    render(
      <TrialCta
        trials={[trial(), trial({ course_slug: "math-1", course_title: "رياضيات ١ ثانوي", subject: "الرياضيات" })]}
        hasIntroVideo={false}
        teacherHref={TEACHER}
        variant="profile"
      />,
    );

    expect(screen.getByRole("link", { name: /الفيزياء ٢ ثانوي/ }).getAttribute("href")).toBe("/courses/physics-2/trial");
    expect(screen.getByRole("link", { name: /رياضيات ١ ثانوي/ }).getAttribute("href")).toBe("/courses/math-1/trial");
  });

  it("sends the phone strip to the courses when there are several", () => {
    render(<TrialCta trials={[trial(), trial({ course_slug: "math-1" })]} hasIntroVideo={false} teacherHref={TEACHER} variant="bar" />);

    expect(screen.getByRole("link", { name: /حصص تجريبية مجانية/ }).getAttribute("href")).toBe("/teachers/ahmed?tab=courses");
  });

  it("falls back to the intro video, from any tab", () => {
    render(<TrialCta trials={[]} hasIntroVideo teacherHref={TEACHER} variant="profile" />);

    expect(screen.getByRole("link", { name: "شاهد فيديو المدرّس" }).getAttribute("href")).toBe("/teachers/ahmed?tab=about#intro-video");
  });

  it("leads to the courses with neither — or no key at all in a cached payload", () => {
    for (const trials of [[], undefined]) {
      const { unmount } = render(<TrialCta trials={trials} hasIntroVideo={false} teacherHref={TEACHER} variant="bar" />);

      expect(screen.getByRole("link", { name: "تصفّح كورسات المدرّس" }).getAttribute("href")).toBe("/teachers/ahmed?tab=courses");
      expect(screen.queryByRole("link", { name: /إنشاء حساب|افتح لوحتك/ })).toBeNull();
      unmount();
    }
  });
});
