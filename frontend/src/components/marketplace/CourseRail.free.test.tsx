import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CourseRail } from "./CourseRail";

/*
| ⛔ «سجّل مجاناً» — `POST /courses/{uuid}/enroll` had no caller anywhere, so a
| genuinely free course could not be entered at all.
|
| ⚠️ Drawn on the server's `free_enrollment` only. A guardian is NOT a student:
| pressing the button would enrol the guardian, so they get the signup link.
*/

const post = vi.fn();
const push = vi.fn();
let mockUser: Record<string, unknown> | null = null;

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { post: (path: string, body: unknown) => post(path, body) },
}));

vi.mock("@/lib/auth-context", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/auth-context")>()),
  useAuth: () => ({ user: mockUser, loading: false }),
}));

vi.mock("@/components/marketplace/CourseOwnership", () => ({
  useCourseOwnership: () => ({ state: "visitor" }),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

const teacher = {
  uuid: "t-1",
  slug: "t",
  name: "مدرّس",
  photo_url: null,
  is_verified: true,
  trust_score: null,
  trust_score_band: "building",
} as never;

function rail(freeEnrollment: boolean) {
  render(
    <CourseRail
      priceMinor={0}
      currency={null}
      courseUuid="c-1"
      isFull={false}
      freeEnrollment={freeEnrollment}
      enrolmentOpen
      privateSubscriptionAvailable
      joinableGroup={false}
      teacher={teacher}
    />,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  mockUser = null;
});

describe("the free course", () => {
  it("enrols a signed-in student in place and takes them to the course", async () => {
    mockUser = { platform_role: "student" };
    post.mockResolvedValue({ data: {} });

    rail(true);
    fireEvent.click(screen.getByRole("button", { name: "سجّل مجاناً" }));

    await vi.waitFor(() => expect(push).toHaveBeenCalledWith("/enrollments/c-1"));
    expect(post).toHaveBeenCalledWith("/courses/c-1/enroll", {});
  });

  it("sends a guest — and a guardian — to sign up, never enrolling them", () => {
    rail(true);
    expect(screen.getByRole("link", { name: "سجّل مجاناً" })).toBeTruthy();

    mockUser = { platform_role: "parent" };
    rail(true);
    expect(screen.queryByRole("button", { name: "سجّل مجاناً" })).toBeNull();
    expect(post).not.toHaveBeenCalled();
  });

  it("keeps the ordinary button on a course that is not free", () => {
    mockUser = { platform_role: "student" };

    rail(false);

    expect(screen.queryByText("سجّل مجاناً")).toBeNull();
    expect(screen.getByText("اشترك بحصص خاصة")).toBeTruthy();
  });
});
