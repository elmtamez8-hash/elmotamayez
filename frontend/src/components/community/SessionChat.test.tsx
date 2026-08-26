import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SessionChat } from "./SessionChat";
import type { Conversation } from "@/lib/conversations";

/*
| «أغلق النقاش» — والمدرّسُ مستثنى من قفلِه.
|
| مَن يُغلقُ النقاشَ ثمّ يجدُ حقلَه مُعطَّلاً لا يستطيعُ أن يجيبَ آخرَ سؤالٍ على الشاشة،
| ولا أن يقولَ لماذا أغلق — فالقفلُ بابٌ بلا مقبضٍ من الجهتَين. الخادمُ يرسمُ الخطَّ
| نفسَه داخل `ConversationPolicy`، وهذه الشاشةُ هي ما يمنعُ الطالبَ من كتابةِ فقرةٍ
| في حقلٍ سيرفضُها.
|
| ⚠️ وهو فرعٌ عكسُه يبدو معقولاً تماماً — «‏مقفول ⇒ عطّل الحقل» — فيسقطُ المدرّس.
*/

const room: Conversation = {
  uuid: "c-1",
  kind: "session",
  student_name: null,
  student_uuid: null,
  counterparty_name: null,
  can_moderate: false,
  is_locked: false,
  student_banned: false,
  last_message: null,
  unread_count: 0,
  updated_at: "2026-08-26T12:00:00Z",
} as unknown as Conversation;

let current: Conversation = room;

vi.mock("@/lib/conversations", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/conversations")>()),
  rooms: {
    open: () => Promise.resolve(current),
    setLock: vi.fn(),
    markHelpful: vi.fn(),
    report: vi.fn(),
  },
  conversations: {
    messages: () => Promise.resolve({ data: [] }),
    send: vi.fn(),
  },
}));

vi.mock("@/lib/echo", () => ({
  listen: () => Promise.resolve(() => undefined),
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: { uuid: "u-1" } }),
}));

describe("SessionChat — the discussion lock", () => {
  beforeEach(() => {
    current = { ...room };
  });

  it("offers the composer while the room is open", async () => {
    render(<SessionChat kind="session" uuid="s-1" />);

    expect(await screen.findByRole("button", { name: "إرسال" })).toBeTruthy();
    expect(screen.queryByText(/النقاش مغلق/)).toBeNull();
  });

  it("shuts the composer for a student and says why", async () => {
    current = { ...room, is_locked: true };

    render(<SessionChat kind="session" uuid="s-1" />);

    expect(await screen.findByText(/النقاش مغلق مؤقّتاً/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: "إرسال" })).toBeNull();
    // And no lock control: closing it is not theirs to undo.
    expect(screen.queryByRole("button", { name: "افتح النقاش" })).toBeNull();
  });

  it("keeps the teacher writing inside the room they just closed", async () => {
    current = { ...room, is_locked: true, can_moderate: true };

    render(<SessionChat kind="session" uuid="s-1" />);

    expect(await screen.findByRole("button", { name: "إرسال" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "افتح النقاش" })).toBeTruthy();
  });

  it("offers no lock in a private thread, where silencing one person is a ban", async () => {
    current = { ...room, kind: "private", can_moderate: true };

    render(<SessionChat kind="session" uuid="s-1" />);

    expect(await screen.findByRole("button", { name: "إرسال" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "أغلق النقاش" })).toBeNull();
  });
});
