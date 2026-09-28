"use client";

import { useEffect, useState } from "react";

import { CHAT_SOUND_CHANGED, isChatSoundMuted, setChatSoundMuted } from "@/lib/chat-sound";

/**
 * «صوت الرسائل» on or off, for this browser.
 *
 * Read after mount, never during render: the server has no `localStorage`, and a
 * value read in the initial state is a hydration mismatch React resolves by
 * silently keeping the server's answer (the shell's nav rail learnt this first).
 */
export function ChatSoundToggle() {
  const [muted, setMuted] = useState(false);

  useEffect(() => {
    setMuted(isChatSoundMuted());

    const onChange = (event: Event) => setMuted((event as CustomEvent<boolean>).detail);

    window.addEventListener(CHAT_SOUND_CHANGED, onChange);

    return () => window.removeEventListener(CHAT_SOUND_CHANGED, onChange);
  }, []);

  return (
    <button
      type="button"
      onClick={() => setChatSoundMuted(!muted)}
      aria-pressed={!muted}
      className="flex items-center gap-1 rounded-full px-3 py-1.5 text-xs text-ink-muted hover:bg-surface-raised focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
    >
      <span aria-hidden="true">{muted ? "🔕" : "🔔"}</span>
      {muted ? "صوت الرسائل مكتوم" : "صوت الرسائل مفعّل"}
    </button>
  );
}
