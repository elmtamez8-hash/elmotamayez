"use client";

import { useEffect, useRef, useState } from "react";

/**
 * The message box (spec 010 · `FR-054` · `FR-066`).
 *
 * ⚠️ THE PICKER IS IMPORTED DYNAMICALLY, ON FIRST PRESS. `emoji-picker-react`
 * ships its whole emoji dataset, and a chat page that downloads it on every load
 * pays for a control most messages never touch — the same reason `hls.js` is
 * loaded only where MSE is actually needed and `laravel-echo` only when a screen
 * asks for a socket. Every failure resolves to «no picker», never to a broken
 * composer: the OS keyboard has an emoji key and the textarea takes it either way.
 *
 * ⚠️ AND THE EMOJI IS INSERTED AT THE CARET, NOT APPENDED. Appending is correct
 * exactly once — when the caret happens to be at the end — and silently wrong
 * every other time, which on an RTL line reads as the character landing at the
 * wrong edge of the sentence.
 *
 * The textarea grows to a ceiling and then scrolls: a box that grows without one
 * pushes the send button off a phone screen at about fifteen lines.
 */
export function Composer({
  value,
  onChange,
  onSend,
  disabled = false,
  error,
  placeholder = "اكتب رسالتك…",
}: {
  value: string;
  onChange: (next: string) => void;
  onSend: () => void;
  disabled?: boolean;
  error?: string;
  placeholder?: string;
}) {
  const [picker, setPicker] = useState<"closed" | "loading" | "open" | "unavailable">("closed");
  const [Picker, setPicker_] = useState<React.ComponentType<{
    onEmojiClick: (emoji: { emoji: string }) => void;
    width: number;
    height: number;
  }> | null>(null);

  const box = useRef<HTMLTextAreaElement | null>(null);

  // Grow to a ceiling, then scroll.
  useEffect(() => {
    const element = box.current;

    if (element === null) return;

    element.style.height = "auto";
    element.style.height = `${Math.min(element.scrollHeight, 160)}px`;
  }, [value]);

  const openPicker = () => {
    if (picker === "open") {
      setPicker("closed");

      return;
    }

    if (Picker !== null) {
      setPicker("open");

      return;
    }

    setPicker("loading");

    import("emoji-picker-react")
      .then((module) => {
        setPicker_(() => module.default);
        setPicker("open");
      })
      .catch(() => setPicker("unavailable"));
  };

  const insert = (emoji: string) => {
    const element = box.current;

    if (element === null) {
      onChange(value + emoji);

      return;
    }

    const start = element.selectionStart;
    const end = element.selectionEnd;

    onChange(value.slice(0, start) + emoji + value.slice(end));

    // Put the caret after what was just inserted, on the next frame — React has
    // not written the new value into the DOM yet.
    requestAnimationFrame(() => {
      element.focus();
      element.setSelectionRange(start + emoji.length, start + emoji.length);
    });
  };

  const send = () => {
    if (disabled || value.trim() === "") return;

    setPicker("closed");
    onSend();
  };

  return (
    <div className="border-t border-border bg-surface p-3">
      {picker === "open" && Picker !== null && (
        <div className="mb-2 flex justify-start">
          <Picker onEmojiClick={(emoji) => insert(emoji.emoji)} width={320} height={360} />
        </div>
      )}

      <div className="flex items-end gap-2">
        <button
          type="button"
          onClick={openPicker}
          disabled={picker === "unavailable"}
          aria-label="أدرج إيموجي"
          aria-expanded={picker === "open"}
          className="grid h-10 w-10 shrink-0 place-items-center rounded-full text-lg text-ink-muted hover:bg-surface-raised disabled:opacity-50"
        >
          {picker === "loading" ? "…" : "☺"}
        </button>

        <div className="min-w-0 flex-1">
          <label htmlFor="composer" className="sr-only">
            رسالتك
          </label>
          <textarea
            id="composer"
            ref={box}
            rows={1}
            value={value}
            disabled={disabled}
            placeholder={placeholder}
            onChange={(event) => onChange(event.target.value)}
            onKeyDown={(event) => {
              /*
               * Enter sends, Shift+Enter breaks the line — the convention every
               * messaging product shares. `isComposing` is the load-bearing half:
               * an IME (and Android's own suggestion bar) fires Enter to ACCEPT a
               * candidate, and without this guard that keystroke sends a
               * half-finished word instead of completing it.
               */
              if (event.key === "Enter" && !event.shiftKey && !event.nativeEvent.isComposing) {
                event.preventDefault();
                send();
              }
            }}
            aria-invalid={error !== undefined}
            className="w-full resize-none rounded-2xl border border-border bg-surface-raised px-4 py-2 text-sm text-ink placeholder:text-ink-muted focus:border-primary focus:outline-none disabled:opacity-60"
          />
        </div>

        <button
          type="button"
          onClick={send}
          disabled={disabled || value.trim() === ""}
          aria-label="إرسال"
          className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary text-white disabled:opacity-40"
        >
          {/* An arrow, mirrored with the page: `rotate-180` in RTL would be a
              second rule to remember, so the glyph itself is direction-neutral. */}
          <svg viewBox="0 0 24 24" className="h-5 w-5" aria-hidden="true" fill="currentColor">
            <path d="M2 21l21-9L2 3v7l15 2-15 2v7z" transform="scale(-1,1) translate(-24,0)" />
          </svg>
        </button>
      </div>

      {error !== undefined && (
        <p role="alert" className="mt-2 text-xs text-danger-ink">
          {error}
        </p>
      )}
    </div>
  );
}
