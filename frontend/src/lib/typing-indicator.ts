"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import type { ChatMember } from "./echo";

/**
 * «يكتب…» — who on the other end is typing, and how often we say that we are.
 *
 * ⚠️ BOTH HALVES ARE TIMERS, BECAUSE A WHISPER HAS NO «STOPPED» EVENT AND NO
 * DELIVERY GUARANTEE. The receiving side forgets a typist after
 * `TYPING_EXPIRY_MS` of silence — without it somebody who typed one letter and
 * closed the tab would be typing on the other screen for ever. The sending side
 * whispers at most once per `WHISPER_INTERVAL_MS`: one frame per keystroke is
 * enough for Reverb's own rate limiter to start dropping them mid-sentence, and
 * the indicator would flicker instead of holding. The interval sits comfortably
 * inside the expiry, so a steady typist never appears to stop.
 */
export const TYPING_EXPIRY_MS = 3000;
export const WHISPER_INTERVAL_MS = 2000;

/** The receiving half: the member currently typing, or null. */
export function useTypingIndicator(): {
  typing: ChatMember | null;
  /** A whisper arrived from this member. */
  heard: (member: ChatMember) => void;
  /** Their message arrived, so they have stopped — clear at once, not in 3 s. */
  settle: (senderUuid: string | null) => void;
} {
  const [typing, setTyping] = useState<ChatMember | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => () => clearTimeout(timer.current), []);

  const heard = useCallback((member: ChatMember) => {
    setTyping(member);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setTyping(null), TYPING_EXPIRY_MS);
  }, []);

  const settle = useCallback((senderUuid: string | null) => {
    setTyping((current) => {
      if (current === null || current.uuid !== senderUuid) return current;

      clearTimeout(timer.current);

      return null;
    });
  }, []);

  return { typing, heard, settle };
}

/**
 * The sending half: a function to call on every keystroke that whispers at most
 * once per interval. `now` is injectable so the throttle is measurable without a
 * clock.
 */
export function throttleWhisper(
  send: () => void,
  interval = WHISPER_INTERVAL_MS,
  now: () => number = Date.now,
): () => void {
  let last = Number.NEGATIVE_INFINITY;

  return () => {
    const at = now();

    if (at - last < interval) return;

    last = at;
    send();
  };
}
