import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { CELEBRATION_MS } from "@/lib/whiteboard/effects";

import { EffectsBar, type EffectsBarProps } from "./EffectsBar";
import { Celebrate } from "./overlays/Celebrate";

const props = (over: Partial<EffectsBarProps> = {}): EffectsBarProps => ({
  sound: true,
  trail: "off",
  onEffect: vi.fn(),
  onSticker: vi.fn(),
  onSound: vi.fn(),
  onTrail: vi.fn(),
  ...over,
});

afterEach(() => vi.useRealTimers());

describe("EffectsBar", () => {
  it("starts each celebration from its own button", () => {
    const p = props();
    render(<EffectsBar {...p} />);

    fireEvent.click(screen.getByRole("button", { name: /تصفيق/ }));
    fireEvent.click(screen.getByRole("button", { name: /نجوم/ }));
    expect(p.onEffect).toHaveBeenNthCalledWith(1, "applause");
    expect(p.onEffect).toHaveBeenNthCalledWith(2, "stars");
  });

  it("offers the stickers only to the editor, and stamps the one pressed", () => {
    const p = props();
    const { rerender } = render(<EffectsBar {...p} />);
    fireEvent.click(screen.getByRole("button", { name: "ممتاز" }));
    expect(p.onSticker).toHaveBeenCalledWith("excellent");

    rerender(<EffectsBar {...props({ onSticker: undefined })} />);
    expect(screen.queryByRole("button", { name: "ممتاز" })).toBeNull();
  });

  it("says the sound reaches students only with the tab's audio, whenever sound is on (US10-2)", () => {
    const { rerender } = render(<EffectsBar {...props({ sound: true })} />);
    expect(screen.getByText(/صوت التبويب/)).toBeTruthy();

    rerender(<EffectsBar {...props({ sound: false })} />);
    expect(screen.queryByText(/صوت التبويب/)).toBeNull();
  });

  it("switches the pointer trail and the sound", () => {
    const p = props();
    render(<EffectsBar {...p} />);

    fireEvent.change(screen.getByRole("combobox"), { target: { value: "neon" } });
    fireEvent.click(screen.getByRole("checkbox"));
    expect(p.onTrail).toHaveBeenCalledWith("neon");
    expect(p.onSound).toHaveBeenCalledWith(false);
  });
});

describe("Celebrate", () => {
  it("ends itself when its time is up", () => {
    vi.useFakeTimers();
    const onDone = vi.fn();
    render(<Celebrate kind="party" onDone={onDone} />);

    act(() => vi.advanceTimersByTime(CELEBRATION_MS - 1));
    expect(onDone).not.toHaveBeenCalled();
    act(() => vi.advanceTimersByTime(1));
    expect(onDone).toHaveBeenCalledTimes(1);
  });
});
