import { act, render } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api";

import { PresenceLoop } from "./PresenceLoop";

/*
| النبضةُ هي ما يُخرِجُ المتصفّح (٢٠٢٦-٠٩-٣٠).
|
| المزوّدُ لا يسحبُ تذكرة، فالطالبةُ التي أخرجَها المدرّسُ يبقى بثُّها جارياً حتى
| يغادرَ متصفّحُها بنفسِه — ولم يكنْ شيءٌ يجعلُه يغادر. رفضُ النبضةِ (٤٠٣) هو تلك
| الإشارة: يتوقّفُ الإيقاعُ فوراً ويُبلَّغُ السبب.
*/

const presence = vi.fn();

vi.mock("@/lib/class-sessions", () => ({
  classSessions: { presence: (uuid: string) => presence(uuid) as Promise<unknown> },
}));

beforeEach(() => {
  vi.clearAllMocks();
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

async function mount(onEvicted: (reason: string) => void) {
  await act(async () => {
    render(<PresenceLoop sessionUuid="s-1" intervalSeconds={30} onEvicted={onEvicted} />);
  });
  await act(() => vi.advanceTimersByTimeAsync(0));
}

describe("PresenceLoop — a refusal is an eviction", () => {
  it("says the teacher removed her, and stops beating at once", async () => {
    presence.mockRejectedValue(
      new ApiError("أخرجك المدرّس من الحصة.", 403, { code: "removed_from_session" }),
    );
    const onEvicted = vi.fn();

    await mount(onEvicted);

    expect(onEvicted).toHaveBeenCalledWith("removed");

    await act(() => vi.advanceTimersByTimeAsync(120_000));
    // One beat, then silence — not six «missed» beats against a door that said no.
    expect(presence).toHaveBeenCalledTimes(1);
  });

  it("reads every other 403 as the room having ended for her", async () => {
    presence.mockRejectedValue(new ApiError("x", 403, { code: "session_not_joinable" }));
    const onEvicted = vi.fn();

    await mount(onEvicted);

    expect(onEvicted).toHaveBeenCalledWith("ended");
  });

  it("does not evict on a dropped beat", async () => {
    // A network blip is not the server's answer; the loop keeps trying.
    presence.mockRejectedValue(new TypeError("Failed to fetch"));
    const onEvicted = vi.fn();

    await mount(onEvicted);
    await act(() => vi.advanceTimersByTimeAsync(30_000));

    expect(onEvicted).not.toHaveBeenCalled();
    expect(presence).toHaveBeenCalledTimes(2);
  });
});
