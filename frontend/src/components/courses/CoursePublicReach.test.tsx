import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CoursePublicReach } from "./CoursePublicReach";

/*
| ⛔ «انشر الكورس» ثمّ لا شيء — 2026-09-26. كورسٌ منشورٌ بظهورٍ «خاص»، أو في
| مساحةٍ خارجَ السوق، يُجيبُ ٤٠٤ على صفحتِه العامّةِ وعلى صفحةِ الاشتراك، ولم يكنْ
| على شاشةِ المدرّسِ ما يقولُ ذلك.
*/
describe("CoursePublicReach", () => {
  it("says why a published course does not reach visitors, reason by reason", () => {
    render(
      <CoursePublicReach
        course={{
          status: "published",
          slug: "physics-3",
          public_listing: { listed: false, blockers: ["private", "workspace_not_in_marketplace"] },
        }}
      />,
    );

    expect(screen.getByText("الكورس منشور لكنه لا يظهر للزوّار")).toBeTruthy();
    expect(screen.getByText(/ظهور الكورس «خاص»/)).toBeTruthy();
    expect(screen.getByText(/أنت غير معروض في السوق حالياً/)).toBeTruthy();
    expect(screen.queryByText(/ملفّك التدريسي/)).toBeNull();
  });

  it("sends the teacher to the visibility field they can change", () => {
    render(
      <CoursePublicReach
        course={{
          status: "published",
          slug: "physics-3",
          can_change_visibility: true,
          public_listing: { listed: false, blockers: ["private"] },
        }}
      />,
    );

    expect(screen.getByText(/اختر «عام» في «ظهور الكورس»/)).toBeTruthy();
    expect(screen.queryByText(/اطلب من مدرّس الكورس/)).toBeNull();
  });

  it("tells an assistant who can make it public, never to use a field they do not have", () => {
    for (const canChange of [false, undefined]) {
      const { unmount } = render(
        <CoursePublicReach
          course={{
            status: "published",
            slug: "physics-3",
            can_change_visibility: canChange,
            public_listing: { listed: false, blockers: ["private"] },
          }}
        />,
      );

      expect(screen.getByText(/اطلب من مدرّس الكورس جعله عامًّا/)).toBeTruthy();
      expect(screen.queryByText(/اختر «عام»/)).toBeNull();
      unmount();
    }
  });

  it("links the public page when the course is reachable", () => {
    render(
      <CoursePublicReach
        course={{ status: "published", slug: "physics-3", public_listing: { listed: true, blockers: [] } }}
      />,
    );

    expect(screen.getByText("الكورس منشور ويظهر للجميع")).toBeTruthy();
    expect(screen.getByRole("link").getAttribute("href")).toBe("/courses/physics-3");
  });

  it("warns about nothing on a draft — every course starts as one", () => {
    const { container } = render(
      <CoursePublicReach
        course={{ status: "draft", slug: "x", public_listing: { listed: false, blockers: ["draft", "private"] } }}
      />,
    );

    expect(container.textContent).toBe("");
  });

  it("claims nothing when the server did not say — absent is not «reachable»", () => {
    const { container } = render(<CoursePublicReach course={{ status: "published", slug: "x" }} />);

    expect(container.textContent).toBe("");
  });
});
