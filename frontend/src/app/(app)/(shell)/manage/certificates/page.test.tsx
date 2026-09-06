import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ManageCertificatesPage from "./page";

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
}));

function row(index: number) {
  return {
    uuid: `u${index}`,
    certificate_number: `CERT-2026-000${index}`,
    verification_code: `code${index}`,
    issue_reason: "course_completed",
    issued_at: "2026-05-14T10:22:00Z",
    course_title: "أساسيات الجبر",
    student_name: `طالب ${index}`,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("ManageCertificatesPage", () => {
  /*
    ⚠️ «عرض المزيد» NEVER APPEARED ON THE REAL SCREEN. The endpoint answered
    `response()->json(Resource::collection($paginator))`, which does not call
    `toResponse()`, so `meta` was dropped in silence and `last_page` fell back to
    `1` — a teacher with a second year of students read a list that stopped
    without saying so. Both halves are measured: the payload carries `meta`
    (pest), and the button appears when it does (here).
  */
  it("offers a second page when the payload says there is one", async () => {
    get.mockResolvedValue({ data: [row(1)], meta: { last_page: 2 } });

    await act(async () => {
      render(<ManageCertificatesPage />);
    });

    expect(screen.getByRole("button", { name: "عرض المزيد" })).toBeDefined();
  });

  it("appends the next page rather than replacing what is on screen", async () => {
    get.mockResolvedValueOnce({ data: [row(1)], meta: { last_page: 2 } });
    get.mockResolvedValueOnce({ data: [row(2)], meta: { last_page: 2 } });

    await act(async () => {
      render(<ManageCertificatesPage />);
    });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "عرض المزيد" }));
    });

    expect(get).toHaveBeenLastCalledWith("/certificates?page=2");
    expect(screen.getByText("طالب 1")).toBeDefined();
    expect(screen.getByText("طالب 2")).toBeDefined();
  });

  it("offers no second page when there is none", async () => {
    get.mockResolvedValue({ data: [row(1)], meta: { last_page: 1 } });

    await act(async () => {
      render(<ManageCertificatesPage />);
    });

    expect(screen.queryByRole("button", { name: "عرض المزيد" })).toBeNull();
  });

  it("links to the design screen, which nothing else reaches", async () => {
    get.mockResolvedValue({ data: [], meta: { last_page: 1 } });

    await act(async () => {
      render(<ManageCertificatesPage />);
    });

    const link = screen.getByRole("link", { name: "تصميم الشهادة" });

    expect(link.getAttribute("href")).toBe("/manage/certificates/design");
  });
});
