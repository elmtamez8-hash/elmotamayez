"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";

/**
 * The bar above a thread (spec 010 · `FR-054` · `FR-064`).
 *
 * ⚠️ THE BACK LINK IS A LINK, NOT `router.back()`. A thread is reached from a
 * notification as often as from the list, and history-back from a notification
 * lands the reader wherever they were before the app — which is usually nowhere.
 * `hidden md:hidden` is deliberate too: on a wide screen the list is already
 * beside the thread, so a back arrow there points at a pane the reader can see.
 *
 * ⚠️ AND THE BAN CONTROL IS ABSENT, NOT DISABLED, FOR ANYONE WHO MAY NOT USE IT.
 * `can_moderate` is the server's own answer — a greyed-out «احظر» on a student's
 * screen tells them a power exists over them and invites them to try it.
 */
export function ChatHeader({
  title,
  subtitle,
  canModerate,
  banned,
  onBanToggle,
  busy = false,
}: {
  title: string;
  subtitle?: string | null;
  canModerate: boolean;
  banned: boolean;
  onBanToggle: () => void;
  busy?: boolean;
}) {
  const [menu, setMenu] = useState(false);
  const wrap = useRef<HTMLDivElement | null>(null);

  // Close on an outside press. A menu that only closes on its own button is one
  // a phone user escapes by navigating away.
  useEffect(() => {
    if (!menu) return;

    const close = (event: MouseEvent) => {
      if (wrap.current !== null && !wrap.current.contains(event.target as Node)) setMenu(false);
    };

    document.addEventListener("mousedown", close);

    return () => document.removeEventListener("mousedown", close);
  }, [menu]);

  return (
    <header className="flex items-center gap-2 border-b border-line bg-surface px-3 py-2">
      <Link
        href="/messages"
        aria-label="رجوع إلى المحادثات"
        className="grid h-9 w-9 shrink-0 place-items-center rounded-full text-ink-muted hover:bg-surface-raised md:hidden"
      >
        {/* Points to the start edge, which RTL mirrors on its own. */}
        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
          <path d="M9 6l6 6-6 6" />
        </svg>
      </Link>

      <span
        aria-hidden="true"
        className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary-soft text-sm font-semibold text-primary"
      >
        {title.trim().charAt(0)}
      </span>

      <div className="min-w-0 flex-1">
        <h1 className="truncate text-sm font-semibold text-ink">{title}</h1>
        {subtitle !== null && subtitle !== undefined && (
          <p className="truncate text-xs text-ink-muted">{subtitle}</p>
        )}
      </div>

      {canModerate && (
        <div className="relative shrink-0" ref={wrap}>
          <button
            type="button"
            onClick={() => setMenu((open) => !open)}
            aria-label="خيارات المحادثة"
            aria-expanded={menu}
            className="grid h-9 w-9 place-items-center rounded-full text-ink-muted hover:bg-surface-raised"
          >
            <svg viewBox="0 0 24 24" className="h-5 w-5" fill="currentColor" aria-hidden="true">
              <circle cx="12" cy="5" r="2" />
              <circle cx="12" cy="12" r="2" />
              <circle cx="12" cy="19" r="2" />
            </svg>
          </button>

          {menu && (
            <div className="absolute end-0 top-full z-10 mt-1 w-56 overflow-hidden rounded-xl border border-line bg-surface shadow-lg">
              <button
                type="button"
                disabled={busy}
                onClick={() => {
                  setMenu(false);
                  onBanToggle();
                }}
                className="block w-full px-4 py-3 text-start text-sm text-danger-ink hover:bg-surface-raised disabled:opacity-50"
              >
                {banned ? "فكّ الحظر عن الكتابة" : "احظر الكتابة في هذه المساحة"}
              </button>

              <p className="border-t border-line px-4 py-2 text-xs text-ink-muted">
                {/* The ban is workspace-wide by declaration (`FR-022`), and a
                    control that read «احظر في هذه المحادثة» would be lying about
                    a scope the server does not have. */}
                الحظر يمنع الكتابة في مساحتك كلّها، والقراءة تبقى.
              </p>
            </div>
          )}
        </div>
      )}
    </header>
  );
}
