"use client";

import { Badge } from "@/components/ui/Badge";
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
  showBadges = false,
}: {
  messages: ChatMessage[];
  currentUserUuid: string | null;
  onHide?: (uuid: string) => void;
  /**
   * The rank and level beside the name — public rooms only (FR-019).
   *
   * ⚠️ OFF BY DEFAULT, AND THE DEFAULT IS THE DECISION. A badge in front of the
   * class is social pride; the same badge in a one-to-one thread with the teacher
   * is a score attached to a private question. The server sends null there
   * anyway, so this flag is the second half of one rule rather than a duplicate
   * of it.
   */
  showBadges?: boolean;
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

            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-ink-muted">
              <span>{message.sender_name ?? "—"}</span>

              {/* ⚠️ RENDERED ONLY WHEN THERE IS SOMETHING TO RENDER. `null` is the
                  answer for every teacher, every assistant and every student on
                  their first day — an empty badge, a dash, or a zero would each
                  put «المركز ٠» beside the teacher's own name. */}
              {showBadges && message.sender_level !== null && (
                <Badge tone="info">
                  <bdi>{`المستوى ${message.sender_level}`}</bdi>
                </Badge>
              )}

              {showBadges && message.sender_rank !== null && (
                <Badge tone="success">
                  <bdi>{`المركز ${message.sender_rank}`}</bdi>
                </Badge>
              )}

              {message.is_helpful && <Badge tone="success">إجابة معتمَدة</Badge>}

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
