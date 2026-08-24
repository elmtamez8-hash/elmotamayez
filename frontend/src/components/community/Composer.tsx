"use client";

import { useEffect, useRef, useState } from "react";

/** Matches `MediaLimits::maxVoiceNoteSeconds()`; the server refuses beyond it. */
const MAX_VOICE_SECONDS = 300;

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
/** A picture or a recording, chosen but not yet sent. */
export type PendingAttachment =
  | { kind: "image"; file: Blob; preview: string }
  | { kind: "voice"; file: Blob; preview: string; seconds: number };

export function Composer({
  value,
  onChange,
  onSend,
  disabled = false,
  error,
  placeholder = "اكتب رسالتك…",
  pending = null,
  onAttachmentChange = () => {},
}: {
  value: string;
  onChange: (next: string) => void;
  onSend: () => void;
  disabled?: boolean;
  error?: string;
  placeholder?: string;
  pending?: PendingAttachment | null;
  onAttachmentChange?: (next: PendingAttachment | null) => void;
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
    if (disabled || (value.trim() === "" && pending === null)) return;

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

      {pending !== null && (
        <div className="mb-2 flex items-center gap-3 rounded-2xl border border-border bg-surface-raised p-2">
          {pending.kind === "image" ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={pending.preview} alt="" className="h-14 w-14 rounded-xl object-cover" />
          ) : (
            <span className="grid h-14 w-14 place-items-center rounded-xl bg-primary-soft text-primary-ink">
              🎤
            </span>
          )}

          <span className="flex-1 truncate text-xs text-ink-muted">
            {pending.kind === "image" ? "صورة جاهزة للإرسال" : `تسجيل ${pending.seconds} ثانية`}
          </span>

          <button
            type="button"
            onClick={() => onAttachmentChange(null)}
            className="rounded-full px-3 py-1 text-xs text-danger-ink underline"
          >
            إزالة
          </button>
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

        {/* A picture. `capture` is deliberately absent: on a phone its presence
            opens the camera and hides the gallery, and most of what a student
            sends is a photograph they already took of their exercise book. */}
        <label className="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-full text-ink-muted hover:bg-surface-raised">
          <span className="sr-only">أرفق صورة</span>
          <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <circle cx="8.5" cy="10" r="1.5" />
            <path d="M21 16l-5-5-6 6" />
          </svg>
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            className="hidden"
            disabled={disabled}
            onChange={(event) => {
              const file = event.target.files?.[0];

              // Reset the input so choosing the SAME file twice still fires
              // `change` — otherwise removing a picture and re-picking it does
              // nothing at all.
              event.target.value = "";

              if (file) onAttachmentChange({ kind: "image", file, preview: URL.createObjectURL(file) });
            }}
          />
        </label>

        <VoiceButton disabled={disabled} onRecorded={onAttachmentChange} />

        <button
          type="button"
          onClick={send}
          disabled={disabled || (value.trim() === "" && pending === null)}
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

/**
 * Hold to record, release to keep (`FR-060`).
 *
 * ⚠️ `MediaRecorder` IS NATIVE AND NEEDS NO LIBRARY — but the CONTAINER it
 * produces is not the same everywhere: Chrome and Firefox give `audio/webm;
 * codecs=opus`, Safari gives `audio/mp4`. Naming a type outright throws
 * `NotSupportedError` on whichever browser disagrees, so the recorder is left to
 * choose and the server records what actually arrived — `media_assets.mime_type`
 * is written from the file, not from a guess.
 *
 * ⚠️ AND THE MICROPHONE TRACKS ARE STOPPED EXPLICITLY. Ending the recorder does
 * not release the device: without `getTracks().forEach(stop)` the browser leaves
 * its recording indicator lit for the rest of the session, which reads as an app
 * that is still listening.
 *
 * ⚠️ THE GOVERNANCE IS WRITTEN DOWN RATHER THAN IMPLIED: no automatic filter can
 * read audio. `TermFilter` sees letters, not sound, so a voice note is governed by
 * reporting, hiding and banning alone (`FR-062`).
 */
function VoiceButton({
  disabled,
  onRecorded,
}: {
  disabled: boolean;
  onRecorded: (next: PendingAttachment) => void;
}) {
  const [state, setState] = useState<"idle" | "recording" | "denied">("idle");
  const [seconds, setSeconds] = useState(0);

  const recorder = useRef<MediaRecorder | null>(null);
  const chunks = useRef<Blob[]>([]);
  const ticker = useRef<number | undefined>(undefined);

  const stop = () => {
    recorder.current?.stop();
    window.clearInterval(ticker.current);
  };

  const start = async () => {
    if (state === "recording") {
      stop();

      return;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const media = new MediaRecorder(stream);

      chunks.current = [];
      media.ondataavailable = (event) => chunks.current.push(event.data);

      media.onstop = () => {
        // Releases the microphone AND the browser's recording indicator.
        stream.getTracks().forEach((track) => track.stop());

        const blob = new Blob(chunks.current, { type: media.mimeType });

        setState("idle");

        // A tap that produced nothing is a tap, not a recording.
        if (blob.size === 0) return;

        onRecorded({
          kind: "voice",
          file: blob,
          preview: URL.createObjectURL(blob),
          seconds: Math.max(1, Math.round((Date.now() - startedAt.current) / 1000)),
        });
      };

      startedAt.current = Date.now();
      setSeconds(0);
      media.start();
      recorder.current = media;
      setState("recording");

      ticker.current = window.setInterval(() => {
        setSeconds((n) => {
          // A hard ceiling in the UI as well as on the server: a phone left in a
          // pocket would otherwise record until the tab is closed.
          if (n + 1 >= MAX_VOICE_SECONDS) stop();

          return n + 1;
        });
      }, 1000);
    } catch {
      // A refused microphone is not an error a banner should shout about — the
      // control simply stops offering itself.
      setState("denied");
    }
  };

  const startedAt = useRef(0);

  if (state === "denied") return null;

  return (
    <button
      type="button"
      onClick={start}
      disabled={disabled}
      aria-label={state === "recording" ? "أوقف التسجيل" : "سجّل رسالة صوتية"}
      className={
        state === "recording"
          ? "grid h-10 shrink-0 place-items-center rounded-full bg-danger px-3 text-xs font-semibold text-white"
          : "grid h-10 w-10 shrink-0 place-items-center rounded-full text-ink-muted hover:bg-surface-raised"
      }
    >
      {state === "recording" ? (
        <span className="tabular-nums">{seconds}s ⏹</span>
      ) : (
        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
          <rect x="9" y="3" width="6" height="11" rx="3" />
          <path d="M5 11a7 7 0 0 0 14 0M12 18v3" />
        </svg>
      )}
    </button>
  );
}
