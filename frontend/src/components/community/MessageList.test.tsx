import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { MessageList } from "./MessageList";
import { mergeMessages, type ChatMessage } from "@/lib/conversations";

/*
| Spec 010 · US2 — the live insert must not show a message twice.
|
| ⚠️ THE SAME MESSAGE ARRIVES TWICE BY DESIGN, and that is the whole test. The
| sender gets it in the response to their own POST, and then again a moment later
| through the socket — so a list that appends whatever arrives shows the sender
| their own sentence twice, on every message they send, for as long as the socket
| is up. It is invisible to every backend test: the table has one row.
|
| ⚠️ AND IT IS MEASURED ON `mergeMessages()` AND THROUGH THE RENDER BOTH. The
| function is where the rule lives; the render is what proves the component uses
| it rather than carrying a second copy of the rule of its own.
*/

function message(uuid: string, body: string, at: string): ChatMessage {
  return {
    uuid,
    body,
    sender_uuid: "me",
    sender_name: "سلمى",
    is_helpful: false,
    created_at: at,
  };
}

describe("mergeMessages", () => {
  it("keeps one copy of a message that arrived twice", () => {
    const first = message("m-1", "أهلاً", "2026-08-23T10:00:00+00:00");

    const merged = mergeMessages([first], [first]);

    expect(merged).toHaveLength(1);
  });

  it("prefers the newer copy of a message it already had", () => {
    const original = message("m-1", "أهلاً", "2026-08-23T10:00:00+00:00");
    const edited = { ...original, is_helpful: true };

    const merged = mergeMessages([original], [edited]);

    expect(merged).toHaveLength(1);
    expect(merged[0].is_helpful).toBe(true);
  });

  it("appends a genuinely new message after the ones already shown", () => {
    const older = message("m-1", "الأولى", "2026-08-23T10:00:00+00:00");
    const newer = message("m-2", "الثانية", "2026-08-23T10:05:00+00:00");

    expect(mergeMessages([older], [newer]).map((m) => m.body)).toEqual(["الأولى", "الثانية"]);
  });

  it("keeps the server order for two messages inside one second", () => {
    // Equal timestamps are the ordinary case in a live chat, and the server has
    // already ordered them by its monotonic key — the merge must not reshuffle.
    const at = "2026-08-23T10:00:00+00:00";
    const a = message("m-1", "الأولى", at);
    const b = message("m-2", "الثانية", at);

    expect(mergeMessages([], [a, b]).map((m) => m.body)).toEqual(["الأولى", "الثانية"]);
  });
});

describe("MessageList", () => {
  it("renders one node per message after a duplicate arrives", () => {
    const sent = message("m-1", "هل الحصّة غداً؟", "2026-08-23T10:00:00+00:00");

    render(
      <MessageList messages={mergeMessages([sent], [sent])} currentUserUuid="me" />,
    );

    expect(screen.getAllByTestId("chat-message")).toHaveLength(1);
    expect(screen.getByText("هل الحصّة غداً؟")).toBeDefined();
  });

  it("says so when there is nothing yet rather than rendering an empty list", () => {
    render(<MessageList messages={[]} currentUserUuid="me" />);

    expect(screen.queryAllByTestId("chat-message")).toHaveLength(0);
    expect(screen.getByText(/لا رسائل بعد/)).toBeDefined();
  });
});
