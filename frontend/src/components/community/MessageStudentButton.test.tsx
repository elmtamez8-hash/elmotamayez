import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MessageStudentButton } from "./MessageStudentButton";

/*
 * «راسِل» — the teacher's side opening the private thread, or the compose view
 * when there is none yet (2026-09-28: no conversation before its first message).
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
  let threads: { uuid: string; kind: string; student_uuid: string | null }[] = [];

  beforeEach(() => {
    api.get.mockReset();
    api.post.mockReset();
    push.mockReset();
    auth.user = { permissions: ["chat.reply"] };
    threads = [];

    api.get.mockImplementation((path: string) => {
      if (path === "/workspaces") {
        return Promise.resolve({
          data: [
            { uuid: "w-other", is_current: false },
            { uuid: "w-1", is_current: true },
          ],
        });
      }

      if (path === "/conversations") return Promise.resolve({ data: threads });

      return Promise.reject(new Error(`unexpected GET ${path}`));
    });
  });

  it("opens the compose view in the CURRENT workspace — and creates nothing", async () => {
    render(<MessageStudentButton studentUuid="s-1" studentName="مريم" />);

    await userEvent.click(screen.getByRole("button", { name: "راسِل مريم" }));

    await waitFor(() =>
      expect(push).toHaveBeenCalledWith("/messages/new?workspace=w-1&student=s-1&name=%D9%85%D8%B1%D9%8A%D9%85"),
    );

    // ⛔ No POST: the conversation is born with its first message (2026-09-28).
    expect(api.post).not.toHaveBeenCalled();
  });

  it("goes straight to the thread that already has messages", async () => {
    threads = [
      { uuid: "c-room", kind: "session", student_uuid: null },
      { uuid: "c-9", kind: "private", student_uuid: "s-1" },
    ];

    render(<MessageStudentButton studentUuid="s-1" studentName="مريم" />);

    await userEvent.click(screen.getByRole("button", { name: "راسِل مريم" }));

    await waitFor(() => expect(push).toHaveBeenCalledWith("/messages/c-9"));
    expect(api.post).not.toHaveBeenCalled();
  });

  it("shows a failure as a sentence and stays put", async () => {
    api.get.mockRejectedValue(new Error("Request failed with status 500"));

    render(<MessageStudentButton studentUuid="s-1" studentName="مريم" />);

    await userEvent.click(screen.getByRole("button", { name: "راسِل مريم" }));

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.queryByText(/status 500/)).toBeNull();
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
