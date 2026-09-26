import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

/*
| بلاغُ ٢٠٢٦-٠٩-٢٦ — «احجز مقعدك من صفحة مدرّسك» على جدولٍ فارغٍ تُسمّي صفحةً ولا
| تعطي طريقاً إليها، وطالبٌ جديدٌ لا يعرفُ بعدُ أينَ صفحةُ مدرّسه.
*/

vi.mock("@/lib/class-sessions", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/class-sessions")>();

  return {
    ...actual,
    classSessions: {
      ...actual.classSessions,
      schedule: () => Promise.resolve({ data: [] }),
      next: () => Promise.resolve({ data: null, seconds_until_start: null }),
    },
  };
});

vi.mock("@/lib/viewer-time-zone", () => ({
  useViewerTimeZone: () => "Asia/Qatar",
}));

import SchedulePage from "./page";

describe("/schedule with nothing booked", () => {
  it("links to where a seat is booked", async () => {
    render(<SchedulePage />);

    expect(await screen.findByText("لا حصص محجوزة")).toBeDefined();
    expect(screen.getByRole("link", { name: "تصفّح المدرّسين" }).getAttribute("href")).toBe(
      "/teachers",
    );
  });
});
