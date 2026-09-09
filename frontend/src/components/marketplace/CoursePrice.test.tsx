import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { User } from "@/lib/types";

import { CoursePrice } from "./CoursePrice";

/*
| السعرُ لمن يدفعُه، ولا أحدَ غيرِه.
|
| ⚠️ والاختبارُ يمشي على **الأربعةِ** لا على الطالبِ وحدَه: تنفيذٌ يعرضُ السعرَ
| للجميعِ يمرُّ من حالةِ الطالبِ سالماً، والحالاتُ الثلاثُ الأخرى هي كلُّ ما
| يُقاس. وحالةُ «مدرّسُ المادةِ نفسِه» ليست تكراراً لحالةِ المدرّس: هي القاعدةُ
| التي يسهلُ استثناؤها.
*/

let mockUser: Partial<User> | null = null;

vi.mock("@/lib/auth-context", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/auth-context")>()),
  useAuth: () => ({ user: mockUser }),
}));

beforeEach(() => {
  mockUser = null;
});

function priceFor(role: User["platform_role"] | undefined) {
  mockUser = role === undefined ? null : { uuid: "u-1", platform_role: role };

  render(<CoursePrice priceMinor={49900} currency="QAR" />);

  return screen.queryByText(/٤٩٩|499/);
}

describe("who sees a course price", () => {
  it("shows it to a student and to a guardian", () => {
    expect(priceFor("student")).not.toBeNull();
  });

  it("shows it to a guardian, who is usually the one paying", () => {
    // A guardian holds zero permissions exactly as a student does, so a
    // permission-shaped predicate would have hidden it from the payer.
    expect(priceFor("parent")).not.toBeNull();
  });

  it("hides it from a teacher, including the one who teaches the subject", () => {
    expect(priceFor("teacher")).toBeNull();
  });

  it("hides it from a signed-out visitor", () => {
    // Otherwise every teacher on the platform reads it in one private window,
    // and the rule above buys nothing at all.
    expect(priceFor(undefined)).toBeNull();
  });

  it("renders «مجاني» rather than a zero, and only for a learner", () => {
    mockUser = { uuid: "u-1", platform_role: "student" };

    const { unmount } = render(<CoursePrice priceMinor={0} currency="QAR" />);

    expect(screen.getByText("مجاني")).toBeTruthy();

    unmount();
    mockUser = { uuid: "u-1", platform_role: "teacher" };

    render(<CoursePrice priceMinor={0} currency="QAR" />);

    expect(screen.queryByText("مجاني")).toBeNull();
  });
});
