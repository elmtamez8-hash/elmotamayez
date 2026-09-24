import { act, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

/*
| The course overview showed no status at all, so a teacher who had published
| every item read a DRAFT course as live (reported 2026-09-24).
*/
const get = vi.fn();

vi.mock("@/lib/api", () => ({ api: { get: (path: string) => get(path) } }));

const { default: CourseDetailPage } = await import("./page");

function course(status: string) {
  return {
    uuid: "c-1",
    title: "رياضيات",
    description: "",
    status,
    is_free: true,
    price_minor: 0,
    currency: "QAR",
    is_sequential: false,
    sections: [],
  };
}

async function openPage(status: string) {
  get.mockResolvedValue(course(status));

  await act(async () => {
    render(<CourseDetailPage params={Promise.resolve({ uuid: "c-1" })} />);
  });
}

describe("the course status badge", () => {
  it("says a draft course is a draft", async () => {
    await openPage("draft");

    expect(screen.getByText("مسودّة")).toBeTruthy();
  });

  it("says a published course is published", async () => {
    await openPage("published");

    expect(screen.getByText("منشور")).toBeTruthy();
  });
});
