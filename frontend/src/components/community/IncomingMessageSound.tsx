"use client";

import { usePathname } from "next/navigation";
import { useEffect, useRef } from "react";

import { useAuth } from "@/lib/auth-context";
import {
  isChatSoundMuted,
  playChatSound,
  primeChatSound,
  shouldChime,
} from "@/lib/chat-sound";
import { listen } from "@/lib/echo";

/**
 * A short sound when a chat message arrives that the reader is not looking at.
 *
 * ⚠️ MOUNTED IN THE SHELL, NOT ON `/messages`. The message that most needs a
 * sound is the one that arrives while the reader is somewhere else in the product
 * — or in another tab — and a listener that lived on the chat screen would be
 * silent exactly then.
 *
 * ⚠️ `user.{uuid}` IS ALREADY «SOMEONE ELSE WROTE TO YOU». `MessagePosted`
 * publishes there only to the OTHER parties of a private thread (a public room
 * tells nobody, by design), so the sender never hears their own message and a
 * busy class room never chimes at all.
 *
 * Renders nothing.
 */
export function IncomingMessageSound() {
  const { user } = useAuth();
  const pathname = usePathname();

  // Read inside the frame handler without re-subscribing on every navigation.
  const openConversation = useRef<string | null>(null);

  useEffect(() => {
    openConversation.current = pathname.startsWith("/messages/")
      ? pathname.slice("/messages/".length).split("/")[0] || null
      : null;
  }, [pathname]);

  // Autoplay: the element is unlocked by the first gesture anywhere on the page.
  useEffect(() => {
    const prime = () => {
      primeChatSound();
      window.removeEventListener("pointerdown", prime);
      window.removeEventListener("keydown", prime);
    };

    window.addEventListener("pointerdown", prime);
    window.addEventListener("keydown", prime);

    return () => {
      window.removeEventListener("pointerdown", prime);
      window.removeEventListener("keydown", prime);
    };
  }, []);

  useEffect(() => {
    const uuid = user?.uuid;

    if (uuid === undefined || uuid === null) return;

    let cancelled = false;
    let unsubscribe: (() => void) | null = null;

    listen(`user.${uuid}`, "message.posted", (payload) => {
      const chime = shouldChime({
        hidden: document.visibilityState === "hidden",
        openConversationUuid: openConversation.current,
        incomingConversationUuid: payload.conversation_uuid,
        muted: isChatSoundMuted(),
      });

      if (chime) playChatSound();
    })
      .then((off) => {
        if (cancelled) {
          off();

          return;
        }

        unsubscribe = off;
      })
      // No socket is no sound, and nothing a person can act on — the bell polls.
      .catch(() => undefined);

    return () => {
      cancelled = true;
      unsubscribe?.();
    };
  }, [user?.uuid]);

  return null;
}
