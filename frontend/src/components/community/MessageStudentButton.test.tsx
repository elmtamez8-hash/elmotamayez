import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MessageStudentButton } from "./MessageStudentButton";

/*
 * «راسِل» — the teacher's side opening the private thread.
 *
 * ⚠️ THE ASSERTION IS THE CALL LIST, because the whole defect was a request no
 * screen sent. `POST /conversations` has taken `student` since it shipped; the
 * one thing this component adds is sending it — with the workspace the API is
 * acting in — and landing the teacher in the thread the server answers with.
 */
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
const push = vi.hoisted(() => vi.fn());
const auth = vi.hoisted(() => ({ user: { permissions: ["chat.reply"] } as { permissions: string[] } | null }));

vi.mock("@/lib/api", () => ({
  api,
  fieldErrors: () => ({}),
  ApiError: class ApiError extends Error {},
}));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => auth }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

describe("MessageStudentButton", () => {
  beforeEach(() => {
    api.get.mockReset();
    api.post.mockReset();
    push.mockReset();
    auth.user = { permissions: ["chat.reply"] };

    api.get.mockImplementation((path: string) =>
      path === "/workspaces"
        ? Promise.resolve({
            data: [
              { uuid: "w-other", is_current: false },
              { uuid: "w-1", is_current: true },
            ],
          })
        : Promise.reject(new Error(`unexpected GET ${path}`)),
    );
  });

  it("opens the thread in the CURRENT workspace with that student, then goes to it", async () => {
    api.post.mockResolvedValue({ uuid: "c-9" });

    render(<MessageStudentButton studentUuid="s-1" studentName="مريم" />);

    await userEvent.click(screen.getByRole("button", { name: "راسِل مريم" }));

    await waitFor(() => expect(push).toHaveBeenCalledWith("/messages/c-9"));

    expect(api.get.mock.calls).toEqual([["/workspaces"]]);
    expect(api.post.mock.calls).toEqual([
      ["/conversations", { workspace: "w-1", student: "s-1" }],
    ]);
  });

  it("shows the server's refusal as a sentence and stays put", async () => {
    api.post.mockRejectedValue(new Error("Request failed with status 403"));

    render(<MessageStudentButton studentUuid="s-stranger" studentName="غريب" />);

    await userEvent.click(screen.getByRole("button", { name: "راسِل غريب" }));

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.queryByText(/status 403/)).toBeNull();
    expect(push).not.toHaveBeenCalled();
  });

  it("is not offered to a reader who cannot answer a student", () => {
    // A student holds no permissions; an assistant without `chat.reply` would
    // press a button the policy refuses.
    auth.user = { permissions: [] };

    render(<MessageStudentButton studentUuid="s-1" studentName="مريم" />);

    expect(screen.queryByRole("button")).toBeNull();
    expect(api.get).not.toHaveBeenCalled();
  });
});
