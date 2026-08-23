"use client";

import { formatDateTime } from "@/lib/labels";
import type { ChatMessage } from "@/lib/conversations";

/**
 * One thread, oldest at the top.
 *
 * ⚠️ IT RENDERS WHAT IT IS GIVEN AND MERGES NOTHING. Deduplication lives in
 * `mergeMessages()` beside the data, so it can be measured without a DOM — the
 * same message arrives twice by design, once in the response to the POST and
 * once through the socket, and a component that appended blindly would show the
 * sender their own sentence twice.
 */
export function MessageList({
  messages,
  currentUserUuid,
  onHide,
}: {
  messages: ChatMessage[];
  currentUserUuid: string | null;
  onHide?: (uuid: string) => void;
}) {
  if (messages.length === 0) {
    return (
      <p className="py-8 text-center text-sm text-ink-muted">
        لا رسائل بعد. اكتب أوّل رسالة في الأسفل.
      </p>
    );
  }

  return (
    <ul className="space-y-3">
      {messages.map((message) => {
        const mine = currentUserUuid !== null && message.sender_uuid === currentUserUuid;

        return (
          <li
            key={message.uuid}
            data-testid="chat-message"
            // Logical properties only: `ms-*`/`me-*`, never `ml-*`/`mr-*`.
            className={mine ? "ms-auto max-w-[85%]" : "me-auto max-w-[85%]"}
          >
            <div
              className={
                mine
                  ? "rounded-2xl bg-primary px-4 py-2 text-white"
                  : "rounded-2xl bg-primary-soft px-4 py-2 text-ink"
              }
            >
              <p className="whitespace-pre-wrap break-words text-sm">{message.body}</p>
            </div>

            <div className="mt-1 flex items-center gap-2 text-xs text-ink-muted">
              <span>{message.sender_name ?? "—"}</span>
              <span aria-hidden="true">·</span>
              <span>{formatDateTime(message.created_at)}</span>

              {mine && onHide && (
                <button
                  type="button"
                  onClick={() => onHide(message.uuid)}
                  className="text-danger-ink underline"
                >
                  حذف
                </button>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}
