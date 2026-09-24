import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import MessagesEmptyPane from "./page";

/*
 * The empty pane spoke to students only: a teacher opening their inbox was told
 * to message «مدرّسيك» from «صفحة المدرّس». The teacher's side is decided by
 * `chat.reply`, the same name `ConversationPolicy::teacherSide()` asks.
 */
const auth = vi.hoisted(() => ({ user: { permissions: [] as string[] } }));

vi.mock("@/lib/auth-context", () => ({ useAuth: () => auth }));

describe("MessagesEmptyPane", () => {
  it("speaks to a student about their teachers", () => {
    auth.user = { permissions: [] };

    render(<MessagesEmptyPane />);

    expect(screen.getByText(/مع مدرّسيك/)).toBeTruthy();
    expect(screen.queryByText(/مع طلابك/)).toBeNull();
  });

  it("speaks to a teacher about their students and says where «راسِل» is", () => {
    auth.user = { permissions: ["chat.reply"] };

    render(<MessagesEmptyPane />);

    expect(screen.getByText(/مع طلابك/)).toBeTruthy();
    expect(screen.getByText(/راسِل/)).toBeTruthy();
    expect(screen.queryByText(/صفحة المدرّس/)).toBeNull();
  });
});
