import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import CreateCoursePage from "./page";

/*
| ⛔ «مجاني» is the teacher's decision and nothing else (owner decision
| 2026-09-25). The form sends the flag it shows — unticked by default, so a new
| course is never free by accident — and carries no price field at all.
*/

const post = vi.fn();
const push = vi.fn();

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: {
    get: () => Promise.resolve({ data: [] }),
    post: (path: string, body: unknown) => post(path, body),
  },
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push, back: vi.fn() }) }));

// `importActual` for everything but `useAuth`: the page reads one flag off the
// signed-in account, and the rest of the module stays real.
let mockUser: Record<string, unknown> = { can_choose_course_visibility: true };

vi.mock("@/lib/auth-context", async () => {
  const actual = await vi.importActual<typeof import("@/lib/auth-context")>("@/lib/auth-context");

  return { ...actual, useAuth: () => ({ user: mockUser }) };
});

beforeEach(() => {
  vi.clearAllMocks();
  post.mockResolvedValue({ uuid: "c-1" });
  mockUser = { can_choose_course_visibility: true };
});

function submit() {
  fireEvent.submit(screen.getByRole("button", { name: "أنشئ الكورس" }).closest("form")!);
}

describe("the new course form", () => {
  it("creates a course that is NOT free unless the teacher ticks it", async () => {
    render(<CreateCoursePage />);

    expect(screen.queryByLabelText("السعر")).toBeNull();

    submit();

    await vi.waitFor(() => expect(post).toHaveBeenCalled());
    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(body.is_free_enrollment).toBe(false);
    expect(body).not.toHaveProperty("price_minor");
  });

  it("sends the flag when «كورس مجاني» is ticked", async () => {
    render(<CreateCoursePage />);

    fireEvent.click(screen.getByLabelText(/كورس مجاني/));
    submit();

    await vi.waitFor(() => expect(post).toHaveBeenCalled());
    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(body.is_free_enrollment).toBe(true);
  });

  it("sends the course type picked from the tiles, and none until one is picked", async () => {
    render(<CreateCoursePage />);

    const typeRadios = screen
      .getAllByRole("radio")
      .filter((r) => (r as HTMLInputElement).name === "course_type");
    expect(typeRadios.some((r) => (r as HTMLInputElement).checked)).toBe(false);

    fireEvent.click(screen.getByRole("radio", { name: /جماعي/ }));
    submit();

    await vi.waitFor(() => expect(post).toHaveBeenCalled());
    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(body.course_type).toBe("group");
  });

  it("creates a public course by default, and a private one when the teacher picks it", async () => {
    render(<CreateCoursePage />);

    submit();
    await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect((post.mock.calls[0] as [string, Record<string, unknown>])[1].visibility).toBe("public");

    fireEvent.click(screen.getByRole("radio", { name: /^خاص/ }));
    submit();
    await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect((post.mock.calls[1] as [string, Record<string, unknown>])[1].visibility).toBe("private");
  });
});

/*
| ⛔ «ظهور الكورس» للمدرّسِ وحدَه (قرارُ المالك 2026-09-26): كورسُ المساعدِ
| يُنشأُ «عامّاً» ولا يرى الحقل — والجوابُ من الخادم لا من اسمِ الدور.
*/
describe("«ظهور الكورس» on the new course form", () => {
  it("offers the choice to the teacher, and sends «private» when picked", async () => {
    render(<CreateCoursePage />);

    fireEvent.click(screen.getByRole("radio", { name: /^خاص/ }));
    submit();

    await vi.waitFor(() => expect(post).toHaveBeenCalled());
    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(body.visibility).toBe("private");
  });

  it.each([
    ["an assistant (false)", { can_choose_course_visibility: false }],
    ["an account the server said nothing about", {}],
  ])("hides it from %s, and the course goes out public", async (_label, user) => {
    mockUser = user;
    render(<CreateCoursePage />);

    expect(screen.queryByText("ظهور الكورس")).toBeNull();
    expect(screen.queryByRole("radio", { name: /^خاص/ })).toBeNull();

    submit();

    await vi.waitFor(() => expect(post).toHaveBeenCalled());
    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(body.visibility).toBe("public");
  });
});
