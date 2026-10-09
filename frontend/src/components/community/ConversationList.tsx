"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

import { PresenceAvatar } from "@/components/community/PresenceAvatar";
import { SearchIcon } from "@/components/icons";
import { formatDateTime } from "@/lib/labels";
import type { Conversation } from "@/lib/conversations";

/**
 * The threads, with a filter over them (spec 010 · `FR-054` · `FR-065`).
 *
 * ⚠️ THE TITLE IS `counterparty_name`, NEVER `student_name`. The second is the
 * counterpart for the TEACHER and the reader's own name for the student — so a
 * list built on it titled every row on a student's screen with their own name,
 * on a screen whose whole job is telling threads apart. It survived because every
 * fixture that exercised this list was a teacher's.
 *
 * ⚠️ AND THE SEARCH IS A FILTER OVER WHAT IS ALREADY HERE. The list is capped at
 * one page by the Action, so filtering in the browser needs no endpoint, no
 * limiter and no index — and it stays honest, because it can only ever hide rows
 * the reader was already sent. Searching INSIDE message bodies is a different
 * question with a different answer, and it is not this control.
 */
export function ConversationList({
  rows,
  activeUuid,
  onlineUuids = new Set<string>(),
}: {
  rows: Conversation[];
  activeUuid: string | null;
  /**
   * Threads whose other end has the thread open right now — see
   * `PresenceAvatar` for why that, and not «online», is what a dot can say.
   */
  onlineUuids?: ReadonlySet<string>;
}) {
  const [query, setQuery] = useState("");

  const shown = useMemo(() => {
    const needle = query.trim();

    if (needle === "") return rows;

    return rows.filter((row) => (row.counterparty_name ?? "").includes(needle));
  }, [rows, query]);

  return (
    <div className="flex h-full flex-col">
      <div className="relative p-3">
        <label htmlFor="conversation-search" className="sr-only">
          ابحث في محادثاتك
        </label>
        <input
          id="conversation-search"
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="ابحث بالاسم…"
          className="w-full rounded-full border border-line bg-surface-raised py-2.5 pe-4 ps-10 text-sm text-ink shadow-sm placeholder:text-ink-muted focus:border-primary focus:outline-none"
        />
        <SearchIcon
          aria-hidden="true"
          className="pointer-events-none absolute start-6 top-1/2 -translate-y-1/2 text-ink-muted"
        />
      </div>

      {shown.length === 0 ? (
        <p className="p-6 text-center text-sm text-ink-muted">
          {rows.length === 0 ? "لا محادثات بعد." : "لا نتائج لهذا البحث."}
        </p>
      ) : (
        // `min-h-0`, or a flex child is at least as tall as its content and the
        // overflow never engages — the list grows past the pane and is clipped.
        <ul className="min-h-0 flex-1 space-y-1 overflow-y-auto px-3 pb-3">
          {shown.map((row) => {
            const active = row.uuid === activeUuid;

            return (
              <li key={row.uuid}>
                <Link
                  href={`/messages/${row.uuid}`}
                  aria-current={active ? "page" : undefined}
                  className={
                    "group flex items-start gap-3 rounded-2xl p-3 transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary " +
                    (active
                      ? "bg-primary text-white shadow-md shadow-primary/20"
                      : "hover:bg-surface-raised hover:shadow-sm")
                  }
                >
                  <PresenceAvatar
                    url={row.counterparty_avatar_url ?? null}
                    name={row.counterparty_name ?? "؟"}
                    online={onlineUuids.has(row.uuid)}
                  />

                  <div className="min-w-0 flex-1">
                    <span className={`block truncate font-bold ${active ? "text-white" : "text-ink"}`}>
                      {row.counterparty_name ?? "محادثة"}
                    </span>

                    <div className="flex items-baseline justify-between gap-2">
                      <p className={`min-w-0 truncate text-sm ${active ? "text-white/80" : "text-ink-muted"}`}>
                        {row.last_message === null
                          ? "لا رسائل بعد"
                          : row.last_message.body}
                      </p>
                      <span className={`shrink-0 text-[11px] ${active ? "text-white/75" : "text-ink-muted"}`}>
                        {formatDateTime(row.last_message?.created_at ?? row.updated_at)}
                      </span>
                    </div>
                  </div>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
