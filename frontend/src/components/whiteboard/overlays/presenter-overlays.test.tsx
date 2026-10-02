import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { SPOTLIGHT } from "@/lib/whiteboard/presenter";

import { PresenterBar } from "../PresenterBar";
import { Spotlight } from "./Spotlight";
import { Timer } from "./Timer";

afterEach(() => vi.useRealTimers());

describe("Spotlight", () => {
  it("shows when on, grows and shrinks with ] and [, and goes on Escape", () => {
    const onClose = vi.fn();
    const { container } = render(<Spotlight onClose={onClose} />);
    const layer = container.querySelector("[data-effect='spotlight']");
    expect(layer).not.toBeNull();

    // By position: on an Arabic keyboard this key types «د».
    fireEvent.keyDown(window, { key: "د", code: "BracketRight" });
    expect(layer?.getAttribute("data-radius")).toBe(String(SPOTLIGHT.initial + SPOTLIGHT.step));
    fireEvent.keyDown(window, { key: "ج", code: "BracketLeft" });
    expect(layer?.getAttribute("data-radius")).toBe(String(SPOTLIGHT.initial));

    fireEvent.keyDown(window, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});

describe("Timer", () => {
  it("counts down, pauses, says the time is up, and closes", () => {
    vi.useFakeTimers();
    const onClose = vi.fn();
    render(<Timer minutes={1} sound={false} onClose={onClose} />);
    expect(screen.getByRole("timer").textContent).toBe("١:٠٠");

    act(() => vi.advanceTimersByTime(15_000));
    expect(screen.getByRole("timer").textContent).toBe("٠:٤٥");

    fireEvent.click(screen.getByRole("button", { name: "إيقاف مؤقّت" }));
    act(() => vi.advanceTimersByTime(20_000));
    expect(screen.getByRole("timer").textContent).toBe("٠:٤٥");

    fireEvent.click(screen.getByRole("button", { name: "متابعة" }));
    act(() => vi.advanceTimersByTime(46_000));
    expect(screen.getByRole("timer").textContent).toBe("انتهى الوقت");

    fireEvent.click(screen.getByRole("button", { name: "إغلاق" }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});

describe("PresenterBar", () => {
  it("starts the laser, the spotlight and a countdown from their buttons", () => {
    const props = { spotlight: false, timerRunning: false, onLaser: vi.fn(), onSpotlight: vi.fn(), onTimer: vi.fn() };
    render(<PresenterBar {...props} />);

    fireEvent.click(screen.getByRole("button", { name: "ليزر" }));
    fireEvent.click(screen.getByRole("button", { name: "كشّاف" }));
    fireEvent.click(screen.getByRole("button", { name: /٥ دقائق/ }));

    expect(props.onLaser).toHaveBeenCalled();
    expect(props.onSpotlight).toHaveBeenCalled();
    expect(props.onTimer).toHaveBeenCalledWith(5);
  });
});
