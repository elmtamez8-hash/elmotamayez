"use client";

import Link from "next/link";

import { categoryIcon, categoryTone } from "@/components/notifications/categoryIcons";
import { Button } from "@/components/ui/Button";
import type { NotificationItem } from "@/lib/notifications";

/**
 * One notification, as a row rather than a card of its own.
 *
 * ⚠️ THE WHOLE ROW IS THE TARGET WHEN THERE IS SOMEWHERE TO GO, AND NOTHING IS
 * CLICKABLE WHEN THERE IS NOT. The page used to render every row identically and
 * hang a small «الانتقال» link under the ones that led anywhere — a 14px target
 * at the bottom of a card, on the control the reader opened the page for. A
 * stretched `<Link>` over the article makes the card the button; a row with no
 * `action_url` stays a `<div>`, because a card that looks pressable and does
 * nothing is the defect `LessonRow` was written to end.
 *
 * ⚠️ AND UNREAD IS A DOT PLUS A WORD, NEVER A TINT ALONE. `sr-only` on the word
 * would hide it from everyone who reads the screen rather than hears it, and the
 * whole page is one state sitting beside another.
 *
 * ⚠️ THE SUBJECT ICON IS EMPHASIS, NOT INFORMATION. It sits where the eye lands
 * first and says «هذا عن حصصك» before a word is read — but the subject is
 * WRITTEN beside it too, and the glyph is `aria-hidden`, because an icon alone
 * is a guess for a reader who does not know the product yet and nothing at all
 * to a screen reader. Its colour comes from `categoryTone()`, whose every token
 * is one `@theme` actually defines: Tailwind v4 emits no rule for one it has
 * never seen, and this tree has shipped three invisible states that way.
 */
export function NotificationRow({
  item,
  onRead,
}: {
  item: NotificationItem;
  onRead: (uuid: string) => void;
}) {
  const unread = item.read_at === null;

  const subject = item.category?.key ?? null;

  const body = (
    <>
      <span
        aria-hidden="true"
        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${categoryTone(subject)}`}
      >
        {categoryIcon(subject, "h-5 w-5")}
      </span>

      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted">
          {/* The subject in words, because the icon beside it carries nothing on
              its own. */}
          {item.category !== null && (
            <span className="font-medium text-ink-muted">{item.category.label}</span>
          )}
          <span>· {item.type_label}</span>
          {item.workspace && <span>· {item.workspace.name}</span>}
          {item.subject && <span>· {item.subject.name}</span>}
          {unread && (
            <span className="rounded-full bg-primary-soft px-2 py-0.5 font-medium text-primary-ink">
              جديد
            </span>
          )}
        </span>

        <span className={`mt-1 block ${unread ? "font-semibold text-ink" : "text-ink"}`}>
          {item.title}
        </span>

        <span className="mt-1 block text-sm leading-relaxed text-ink-muted">{item.body}</span>
      </span>
    </>
  );

  const shell = "flex items-start gap-3 rounded-2xl border border-line p-4";

  return (
    <div className="relative isolate">
      {item.action_url !== null ? (
        <Link
          href={item.action_url}
          onClick={() => unread && onRead(item.uuid)}
          className={`${shell} bg-surface-raised transition hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary`}
        >
          {body}
        </Link>
      ) : (
        // No destination: a plain row, not a link to nowhere.
        <div className={`${shell} bg-surface-raised`}>{body}</div>
      )}

      {/*
        Above the row's own click target, so «تعليم كمقروء» does not navigate.
        Absent once read — a control whose only effect has already happened.
      */}
      {unread && (
        <div className="absolute bottom-2 end-3 z-10">
          <Button variant="ghost" size="sm" onClick={() => onRead(item.uuid)}>
            تعليم كمقروء
          </Button>
        </div>
      )}
    </div>
  );
}
