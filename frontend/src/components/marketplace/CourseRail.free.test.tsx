import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CourseRail } from "./CourseRail";

/*
| ⛔ «سجّل مجاناً» — `POST /courses/{uuid}/enroll` had no caller anywhere, so a
| genuinely free course could not be entered at all.
|
| ⚠️ Drawn on the server's `free_enrollment` only. A guardian is NOT a student:
| pressing the button would enrol the guardian, so a signed-in guardian (or
| teacher) is sent to their own home instead.
*/

const post = vi.fn();
const push = vi.fn();
let mockUser: Record<string, unknown> | null = null;
let mockLoading = false;

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: { post: (path: string, body: unknown) => post(path, body) },
}));

vi.mock("@/lib/auth-context", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/auth-context")>()),
  useAuth: () => ({ user: mockUser, loading: mockLoading }),
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

function RailWithFree() {
  return (
    <CourseRail
      courseUuid="c-1"
      isFull={false}
      freeEnrollment
      enrolmentOpen
      privateSubscriptionAvailable={false}
      joinableGroup={false}
      teacher={teacher}
    />
  );
}

function rail(freeEnrollment: boolean) {
  render(
    <CourseRail
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
  mockLoading = false;
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

  it("sends a guest to sign up and back to this course", () => {
    rail(true);

    expect(screen.getByRole("link", { name: "سجّل مجاناً" }).getAttribute("href")).toBe(
      `/signup/student?next=${encodeURIComponent("/courses/c-1")}`,
    );
  });

  it("sends a signed-in guardian or teacher home — never to the signup page, never enrolling them", () => {
    /*
    | ⚠️ A person with an account was being asked to make a second one. The door
    | refuses to enrol either of them, so the button says so and goes home.
    */
    mockUser = { platform_role: "parent" };
    const { unmount } = render(<RailWithFree />);

    expect(screen.queryByRole("button", { name: "سجّل مجاناً" })).toBeNull();
    expect(screen.queryByRole("link", { name: "سجّل مجاناً" })).toBeNull();
    expect(screen.getByRole("link", { name: "العودة إلى صفحتك" }).getAttribute("href")).toBe("/teachers");
    unmount();

    mockUser = { platform_role: "teacher", workspaces: [{ uuid: "w-1", name: "w" }] };
    render(<RailWithFree />);

    expect(screen.getByRole("link", { name: "العودة إلى صفحتك" }).getAttribute("href")).toBe("/dashboard");
    expect(post).not.toHaveBeenCalled();
  });

  it("draws nothing while the session is still being restored", () => {
    mockLoading = true;

    rail(true);

    expect(screen.queryByText("سجّل مجاناً")).toBeNull();
    expect(screen.queryByText("العودة إلى صفحتك")).toBeNull();
  });

  it("keeps the ordinary button on a course that is not free", () => {
    mockUser = { platform_role: "student" };

    rail(false);

    expect(screen.queryByText("سجّل مجاناً")).toBeNull();
    expect(screen.getByText("اشترك بحصص خاصة")).toBeTruthy();
  });

  it("says «مجاني» on a free course and on no other — never read off a zero price", () => {
    /*
    | ⛔ The rail printed `CoursePrice`, which says «مجاني» for `price_minor === 0`
    | — true of every course sold by a plan and never given a one-off price. So a
    | paid course told a signed-in student it was free, right above «اشترك».
    */
    mockUser = { platform_role: "student" };

    const { unmount } = render(
      <CourseRail
        courseUuid="c-1"
        isFull={false}
        freeEnrollment={false}
        enrolmentOpen
        privateSubscriptionAvailable
        joinableGroup={false}
        teacher={teacher}
      />,
    );
    expect(screen.queryByText("مجاني")).toBeNull();
    unmount();

    rail(true);
    expect(screen.getByText("مجاني")).toBeTruthy();
  });
});
