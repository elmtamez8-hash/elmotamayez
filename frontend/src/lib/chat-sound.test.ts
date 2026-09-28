import { describe, expect, it } from "vitest";

import { isChatSoundMuted, setChatSoundMuted, shouldChime } from "./chat-sound";

/*
| The chime for an incoming chat message: only for a message the reader is not
| already looking at, and never when they muted it.
*/
describe("shouldChime", () => {
  const base = {
    hidden: false,
    openConversationUuid: "c-1",
    incomingConversationUuid: "c-1",
    muted: false,
  };

  it("stays quiet for the thread on screen in a visible tab", () => {
    expect(shouldChime(base)).toBe(false);
  });

  it("chimes for another thread", () => {
    expect(shouldChime({ ...base, incomingConversationUuid: "c-2" })).toBe(true);
  });

  it("chimes anywhere else in the product", () => {
    expect(shouldChime({ ...base, openConversationUuid: null })).toBe(true);
  });

  it("chimes for the open thread when the tab is in the background", () => {
    expect(shouldChime({ ...base, hidden: true })).toBe(true);
  });

  it("never chimes once muted", () => {
    expect(shouldChime({ ...base, hidden: true, openConversationUuid: null, muted: true })).toBe(false);
  });
});

describe("the mute preference", () => {
  it("is remembered and announced", () => {
    let heard: unknown = null;
    const listener = (event: Event) => {
      heard = (event as CustomEvent<boolean>).detail;
    };

    window.addEventListener("chat-sound:changed", listener);

    setChatSoundMuted(true);
    expect(isChatSoundMuted()).toBe(true);
    expect(heard).toBe(true);

    setChatSoundMuted(false);
    expect(isChatSoundMuted()).toBe(false);

    window.removeEventListener("chat-sound:changed", listener);
  });
});
