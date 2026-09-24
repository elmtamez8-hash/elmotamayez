import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ExamsPage from "./page";

/*
| «2 سؤالاً» · «30 دقيقة» · «3 محاولات» on one card — production, 2026-09-24.
| Western digits, and a noun each band got wrong somewhere: «2 سؤالاً» is the
| `many` form for a dual, and «محاولات» after eleven is a plural where Arabic
| goes back to the singular. Each count goes through `counted()` now, and each
| is measured at the four bands a template literal cannot tell apart.
*/

const get = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { get: (path: string) => get(path) },
}));

function exam(n: number) {
  return {
    uuid: "e-1",
    course_id: null,
    title: "اختبار الوحدة",
    description: "",
    duration_minutes: n,
    passing_score: 60,
    max_attempts: n,
    status: "published",
    is_published: true,
    questions_count: n,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("the exam card's counts", () => {
  it.each([
    [1, "دقيقة واحدة", "محاولة واحدة", "سؤال واحد"],
    [2, "دقيقتان", "محاولتان", "سؤالان"],
    [3, "٣ دقائق", "٣ محاولات", "٣ أسئلة"],
    [11, "١١ دقيقة", "١١ محاولة", "١١ سؤالاً"],
  ])("%i", async (n, minutes, attempts, questions) => {
    get.mockResolvedValue({ data: [exam(n)] });

    render(<ExamsPage />);

    expect(await screen.findByText(minutes)).toBeTruthy();
    expect(screen.getByText(attempts)).toBeTruthy();
    expect(screen.getByText(questions)).toBeTruthy();
  });
});
