import { act, cleanup, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

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

describe("«اختبارات الكورس»", () => {
  // It opened every course's papers mixed; the list filters on `?course=` now.
  it("opens this course's papers, not the whole list", async () => {
    await openPage("draft");

    expect(screen.getByRole("link", { name: "اختبارات الكورس" }).getAttribute("href")).toBe(
      "/manage/exams?course=c-1",
    );
  });
});

/*
| Spec 040 — the «حصة تجريبية» is optional and ENCOURAGED: a published course
| without one says so to its teacher, and to nobody who cannot choose it.
*/
describe("the trial nudge", () => {
  beforeEach(() => cleanup());

  async function openWith(over: Record<string, unknown>) {
    get.mockResolvedValue({ ...course("published"), ...over });

    await act(async () => {
      render(<CourseDetailPage params={Promise.resolve({ uuid: "c-1" })} />);
    });
  }

  it("tells the teacher a published course has no trial, with the way in", async () => {
    await openWith({ can_choose_trial: true, trial_status: null });

    expect(screen.getByText("كورسك بدون حصة تجريبية")).toBeTruthy();
    expect(screen.getByRole("link", { name: "اختر الحصة التجريبية" }).getAttribute("href")).toBe("/manage/courses/c-1/content");
  });

  it("is silent once there is a trial, for an assistant, and on a draft", async () => {
    for (const over of [
      { can_choose_trial: true, trial_status: "visible" },
      { can_choose_trial: false, trial_status: null },
      { can_choose_trial: true, trial_status: null, status: "draft" },
    ]) {
      await openWith(over);

      expect(screen.queryByText("كورسك بدون حصة تجريبية")).toBeNull();
      cleanup();
    }
  });
});
