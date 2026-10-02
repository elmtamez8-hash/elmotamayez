import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { STUNT_MS, STUNTS } from "@/lib/whiteboard/effects";
import { STICKERS, stickerFileId, stickerOf } from "@/lib/whiteboard/stickers";

import { ATTENTION_MS, AttentionBanner } from "./AttentionBanner";
import { BALLOON_COUNT, BALLOONS_MS, BalloonPop } from "./BalloonPop";
import { Stunt } from "./Stunt";

afterEach(() => vi.useRealTimers());

describe("BalloonPop", () => {
  it("pops a balloon when pressed, and ends once the last is popped", () => {
    vi.useFakeTimers();
    const onDone = vi.fn();
    render(<BalloonPop sound={false} onDone={onDone} />);

    const balloons = screen.getAllByRole("button", { name: "فرقع البالونة" });
    expect(balloons).toHaveLength(BALLOON_COUNT);

    fireEvent.click(balloons[0]);
    expect((balloons[0] as HTMLButtonElement).disabled).toBe(true);
    expect(onDone).not.toHaveBeenCalled();

    for (const b of balloons.slice(1)) fireEvent.click(b);
    act(() => vi.advanceTimersByTime(400));
    expect(onDone).toHaveBeenCalledTimes(1);
  });

  it("ends by itself when nobody pops them", () => {
    vi.useFakeTimers();
    const onDone = vi.fn();
    render(<BalloonPop sound={false} onDone={onDone} />);

    act(() => vi.advanceTimersByTime(BALLOONS_MS));
    expect(onDone).toHaveBeenCalledTimes(1);
  });
});

describe("AttentionBanner", () => {
  it("shows «انتباه!» and then goes", () => {
    vi.useFakeTimers();
    const onDone = vi.fn();
    render(<AttentionBanner onDone={onDone} />);

    expect(screen.getByRole("status").textContent).toBe("انتباه!");
    act(() => vi.advanceTimersByTime(ATTENTION_MS));
    expect(onDone).toHaveBeenCalledTimes(1);
  });
});

describe("stickers", () => {
  it("are stored under ids the server accepts as templates, and read back", () => {
    // The server's own rule (SceneValidator::TEMPLATE): no lookup, no stored bytes.
    const serverTemplate = /^template:[a-z-]+:v\d+$/;
    for (const name of STICKERS) {
      const id = stickerFileId(name);
      expect(id).toMatch(serverTemplate);
      expect(stickerOf(id)).toBe(name);
    }
    expect(stickerOf("template:grid:v1")).toBeNull();
    expect(stickerOf("a2e24a10-c9d4-404c-9eec-068234137643")).toBeNull();
  });
});

describe("Stunt", () => {
  it("acts every scene and is gone when its time is up", () => {
    vi.useFakeTimers();
    for (const kind of STUNTS) {
      const onDone = vi.fn();
      const { container, unmount } = render(<Stunt kind={kind} onDone={onDone} />);
      expect(container.querySelector(`[data-stunt="${kind}"]`), kind).not.toBeNull();
      act(() => vi.advanceTimersByTime(STUNT_MS - 1));
      expect(onDone).not.toHaveBeenCalled();
      act(() => vi.advanceTimersByTime(1));
      expect(onDone, kind).toHaveBeenCalledTimes(1);
      unmount();
    }
  });
});
