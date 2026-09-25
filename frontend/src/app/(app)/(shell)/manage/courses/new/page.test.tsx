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

beforeEach(() => {
  vi.clearAllMocks();
  post.mockResolvedValue({ uuid: "c-1" });
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
});
