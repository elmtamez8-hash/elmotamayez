import { act, fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { formatClock, knownDuration, VoiceNotePlayer } from "./VoiceNotePlayer";

/*
 * A voice note recorded by Chrome is a WebM with no length in its header, so the
 * element reports `Infinity` until the note has been played through — and the
 * browser's own controls showed «0:00 / 0:00» on every bubble (live test,
 * 2026-09-28). The server knows the length; it is the total until the element
 * knows better.
 */

function audioOf(container: HTMLElement): HTMLAudioElement {
  return container.querySelector("audio") as HTMLAudioElement;
}

function setDuration(element: HTMLAudioElement, value: number): void {
  Object.defineProperty(element, "duration", { value, configurable: true });
  fireEvent(element, new Event("durationchange"));
}

describe("VoiceNotePlayer", () => {
  it("shows the server's length while the file does not know its own", () => {
    const { container } = render(<VoiceNotePlayer url="https://files.test/v.webm" durationSeconds={2} mine={false} />);

    expect(screen.getByText("0:00 / 0:02")).toBeTruthy();

    // What Chrome reports for a MediaRecorder WebM before it is played.
    setDuration(audioOf(container), Number.POSITIVE_INFINITY);

    expect(screen.getByText("0:00 / 0:02")).toBeTruthy();
    expect(screen.queryByText("0:00 / 0:00")).toBeNull();
  });

  it("prefers the file's own length once it is finite", () => {
    const { container } = render(<VoiceNotePlayer url="https://files.test/v.webm" durationSeconds={2} mine />);

    setDuration(audioOf(container), 7.4);

    expect(screen.getByText("0:00 / 0:07")).toBeTruthy();
  });

  it("keeps the source URL exactly as the API sent it", () => {
    const url = "https://files.test/v.webm?signature=abc&expires=1";
    const { container } = render(<VoiceNotePlayer url={url} durationSeconds={3} mine={false} />);

    expect(audioOf(container).getAttribute("src")).toBe(url);
  });

  it("plays and pauses with an Arabic label that says what the press will do", () => {
    const { container } = render(<VoiceNotePlayer url="https://files.test/v.webm" durationSeconds={5} mine={false} />);
    const element = audioOf(container);
    let paused = true;

    Object.defineProperty(element, "paused", { get: () => paused, configurable: true });
    element.play = vi.fn(() => {
      paused = false;
      fireEvent(element, new Event("play"));

      return Promise.resolve();
    });
    element.pause = vi.fn(() => {
      paused = true;
      fireEvent(element, new Event("pause"));
    });

    fireEvent.click(screen.getByRole("button", { name: "تشغيل الرسالة الصوتية" }));
    expect(element.play).toHaveBeenCalled();

    // Playback moves the bar and the clock.
    Object.defineProperty(element, "currentTime", { value: 3.2, configurable: true, writable: true });
    fireEvent(element, new Event("timeupdate"));
    expect(screen.getByText("0:03 / 0:05")).toBeTruthy();
    expect(screen.getByRole("slider", { name: "موضع التشغيل" }).getAttribute("aria-valuetext")).toBe("0:03 / 0:05");

    fireEvent.click(screen.getByRole("button", { name: "إيقاف الرسالة الصوتية مؤقتاً" }));
    expect(element.pause).toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "تشغيل الرسالة الصوتية" })).toBeTruthy();
  });

  it("says so on the bubble when the note cannot be played", async () => {
    const { container } = render(<VoiceNotePlayer url="https://files.test/v.webm" durationSeconds={5} mine={false} />);
    const element = audioOf(container);

    Object.defineProperty(element, "paused", { get: () => true, configurable: true });
    element.play = vi.fn(() => Promise.reject(new Error("NotSupportedError")));

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "تشغيل الرسالة الصوتية" }));
    });

    expect(screen.getByText("تعذّر تشغيل الرسالة الصوتية.")).toBeTruthy();
  });

  it("disables seeking when nobody knows the length", () => {
    render(<VoiceNotePlayer url="https://files.test/v.webm" durationSeconds={null} mine={false} />);

    expect(screen.getByRole("slider", { name: "موضع التشغيل" })).toHaveProperty("disabled", true);
    expect(screen.getByText("0:00 / —")).toBeTruthy();
  });
});

describe("knownDuration / formatClock", () => {
  it("falls back to the server for Infinity, NaN and zero", () => {
    expect(knownDuration(Number.POSITIVE_INFINITY, 4)).toBe(4);
    expect(knownDuration(Number.NaN, 4)).toBe(4);
    expect(knownDuration(0, 4)).toBe(4);
    expect(knownDuration(6.5, 4)).toBe(6.5);
    expect(knownDuration(Number.NaN, null)).toBeNull();
  });

  it("writes m:ss", () => {
    expect(formatClock(0)).toBe("0:00");
    expect(formatClock(7.9)).toBe("0:07");
    expect(formatClock(75)).toBe("1:15");
    expect(formatClock(Number.POSITIVE_INFINITY)).toBe("0:00");
  });
});
