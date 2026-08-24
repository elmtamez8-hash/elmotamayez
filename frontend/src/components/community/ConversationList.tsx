"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

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
}: {
  rows: Conversation[];
  activeUuid: string | null;
}) {
  const [query, setQuery] = useState("");

  const shown = useMemo(() => {
    const needle = query.trim();

    if (needle === "") return rows;

    return rows.filter((row) => (row.counterparty_name ?? "").includes(needle));
  }, [rows, query]);

  return (
    <div className="flex h-full flex-col">
      <div className="border-b border-border p-3">
        <label htmlFor="conversation-search" className="sr-only">
          ابحث في محادثاتك
        </label>
        <input
          id="conversation-search"
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="ابحث بالاسم…"
          className="w-full rounded-full border border-border bg-surface px-4 py-2 text-sm text-ink placeholder:text-ink-muted focus:border-primary focus:outline-none"
        />
      </div>

      {shown.length === 0 ? (
        <p className="p-6 text-center text-sm text-ink-muted">
          {rows.length === 0 ? "لا محادثات بعد." : "لا نتائج لهذا البحث."}
        </p>
      ) : (
        <ul className="flex-1 overflow-y-auto">
          {shown.map((row) => {
            const active = row.uuid === activeUuid;

            return (
              <li key={row.uuid}>
                <Link
                  href={`/messages/${row.uuid}`}
                  aria-current={active ? "page" : undefined}
                  className={
                    active
                      ? "flex items-start gap-3 border-b border-border bg-primary-soft p-3"
                      : "flex items-start gap-3 border-b border-border p-3 hover:bg-surface-raised"
                  }
                >
                  <Avatar name={row.counterparty_name} />

                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="truncate font-medium text-ink">
                        {row.counterparty_name ?? "محادثة"}
                      </span>
                      <span className="shrink-0 text-[11px] text-ink-muted">
                        {formatDateTime(row.last_message?.created_at ?? row.updated_at)}
                      </span>
                    </div>

                    <p className="truncate text-sm text-ink-muted">
                      {row.last_message === null
                        ? "لا رسائل بعد"
                        : row.last_message.body}
                    </p>
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

/**
 * The first letter, in a circle.
 *
 * No photo: the thread carries no avatar for either side, and inventing one from
 * a marketplace image would put a teacher's promotional headshot on the student's
 * own row too.
 */
function Avatar({ name }: { name: string | null | undefined }) {
  const letter = (name ?? "؟").trim().charAt(0);

  return (
    <span
      aria-hidden="true"
      className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary-soft text-sm font-semibold text-primary"
    >
      {letter}
    </span>
  );
}
