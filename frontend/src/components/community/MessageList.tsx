"use client";

import { Badge } from "@/components/ui/Badge";
import { formatDate, formatTime } from "@/lib/labels";
import type { ChatMessage } from "@/lib/conversations";

/**
 * One thread, oldest at the top.
 *
 * ⚠️ IT RENDERS WHAT IT IS GIVEN AND MERGES NOTHING. Deduplication lives in
 * `mergeMessages()` beside the data, so it can be measured without a DOM — the
 * same message arrives twice by design, once in the response to the POST and
 * once through the socket, and a component that appended blindly would show the
 * sender their own sentence twice.
 *
 * ⚠️ AND THE DAY SEPARATOR IS NOT A MESSAGE. It carries no `chat-message` test
 * id and no list semantics of its own — a row that counts as a message makes
 * «how many messages are on screen» a question about the calendar, and every
 * assertion built on that count silently wrong on any thread spanning midnight.
 */
export function MessageList({
  messages,
  currentUserUuid,
  onHide,
  onReport,
  onMarkHelpful,
  showBadges = false,
}: {
  messages: ChatMessage[];
  currentUserUuid: string | null;
  onHide?: (uuid: string) => void;
  /** The human path for what the term list did not catch (`FR-064`). */
  onReport?: (uuid: string) => void;
  /** The teacher's endorsement — public rooms only. */
  onMarkHelpful?: (uuid: string) => void;
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
    <ul className="space-y-1 px-3 py-4">
      {messages.map((message, index) => {
        const mine = currentUserUuid !== null && message.sender_uuid === currentUserUuid;
        const previous = index === 0 ? null : messages[index - 1];
        const newDay = startsNewDay(previous, message);

        return (
          <li key={message.uuid}>
            {newDay && (
              <div className="my-4 flex justify-center">
                <span className="rounded-full bg-surface-raised px-3 py-1 text-[11px] text-ink-muted">
                  {formatDate(message.created_at)}
                </span>
              </div>
            )}

            <div
              data-testid="chat-message"
              className={
                "group " + (mine ? "ms-auto max-w-[85%]" : "me-auto max-w-[85%]")
              }
            >
              {/* The sender's name only on the other side, and only when it
                  changes — repeating it on every consecutive bubble is noise in
                  a two-person thread and clutter in a room. */}
              {!mine && showsSender(previous, message) && (
                <div className="mb-1 flex flex-wrap items-center gap-2 px-1 text-xs text-ink-muted">
                  <span className="font-medium">{message.sender_name ?? "—"}</span>

                  {/* ⚠️ RENDERED ONLY WHEN THERE IS SOMETHING TO RENDER. `null` is
                      the answer for every teacher, every assistant and every
                      student on their first day — an empty badge, a dash, or a
                      zero would each put «المركز ٠» beside the teacher's own
                      name. */}
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
                </div>
              )}

              <div
                className={
                  mine
                    ? "rounded-2xl rounded-ee-sm bg-primary px-3 py-2 text-white"
                    : "rounded-2xl rounded-es-sm bg-surface-raised px-3 py-2 text-ink"
                }
              >
                {message.attachment !== null && (
                  <Attachment attachment={message.attachment} mine={mine} />
                )}

                {/* ⚠️ `body` IS NULL ON AN ATTACHMENT-ONLY MESSAGE. Rendering it
                    unconditionally puts an empty paragraph under every picture,
                    which on a bubble reads as a stray blank line. */}
                {message.body !== null && message.body !== "" && (
                  <p className="whitespace-pre-wrap break-words text-sm">{message.body}</p>
                )}

                <div className="mt-1 flex items-center justify-end gap-1">
                  {message.is_helpful && (
                    <span className={mine ? "text-[11px] text-white/80" : "text-[11px] text-success-ink"}>
                      إجابة معتمَدة
                    </span>
                  )}
                  <span className={mine ? "text-[10px] text-white/70" : "text-[10px] text-ink-muted"}>
                    {formatTime(message.created_at)}
                  </span>
                </div>
              </div>

              {/*
                ⚠️ REVEALED ON HOVER FROM `md` UP, ALWAYS VISIBLE BELOW IT. «أبلِغ»
                printed under every single message is an accusation the screen
                keeps making; hidden behind a hover it is there when wanted. But
                a phone has no hover at all, so hiding it there would delete the
                control rather than tidy it — which is why the breakpoint is in
                the rule and not just an opacity.

                `focus-within` is the keyboard half: without it the buttons are
                reachable by Tab and invisible while focused.
              */}
              <div className="mt-1 flex flex-wrap items-center gap-3 px-1 text-[11px] transition-opacity md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100">
                {mine && onHide && (
                  <button type="button" onClick={() => onHide(message.uuid)} className="text-danger-ink underline">
                    حذف
                  </button>
                )}

                {!mine && onMarkHelpful && !message.is_helpful && (
                  <button type="button" onClick={() => onMarkHelpful(message.uuid)} className="text-success-ink underline">
                    اعتمِد الإجابة
                  </button>
                )}

                {!mine && onReport && (
                  <button type="button" onClick={() => onReport(message.uuid)} className="text-ink-muted underline">
                    أبلِغ
                  </button>
                )}
              </div>
            </div>
          </li>
        );
      })}
    </ul>
  );
}

/** True when this message falls on a later calendar day than the one before it. */
function startsNewDay(previous: ChatMessage | null, message: ChatMessage): boolean {
  if (message.created_at === null) return false;
  if (previous === null) return true;
  if (previous.created_at === null) return true;

  return new Date(previous.created_at).toDateString() !== new Date(message.created_at).toDateString();
}

/** True when the sender changed, or a new day started the run. */
function showsSender(previous: ChatMessage | null, message: ChatMessage): boolean {
  if (previous === null) return true;

  return previous.sender_uuid !== message.sender_uuid || startsNewDay(previous, message);
}

/**
 * The picture or the voice note inside a bubble (`FR-060` · `FR-061`).
 *
 * ⚠️ A PLAIN `<img>`, NOT `next/image`. This URL is signed and short-lived, so it
 * is by definition user-supplied and remote — and passing one to `next/image`
 * routes it through `sharp`, whose advisories this repository accepts precisely
 * BECAUSE all three existing call sites pass literal `/public` paths. This would
 * be the call site that makes that note false.
 *
 * ⚠️ AND `<audio controls>` RATHER THAN A PLAYER. A voice note is seconds long
 * and needs play, pause and a scrub bar — every browser ships all three, in the
 * reader's own language, keyboard-accessible. The lesson player exists for HLS,
 * watermarks and grant renewal; none of that applies here.
 */
function Attachment({
  attachment,
  mine,
}: {
  attachment: NonNullable<ChatMessage["attachment"]>;
  mine: boolean;
}) {
  if (attachment.kind === "voice") {
    return (
      <div className="mb-1">
        {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
        <audio controls preload="metadata" src={attachment.url} className="w-56 max-w-full" />
        {attachment.duration_seconds !== null && (
          <span className={mine ? "text-[10px] text-white/70" : "text-[10px] text-ink-muted"}>
            <bdi>{`${attachment.duration_seconds} ثانية`}</bdi>
          </span>
        )}
      </div>
    );
  }

  return (
    <a href={attachment.url} target="_blank" rel="noreferrer" className="mb-1 block">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={attachment.url}
        alt="صورة مرفقة"
        // A ceiling on both axes: a portrait photograph from a phone is taller
        // than the viewport, and one message would otherwise fill the thread.
        className="max-h-72 w-auto max-w-full rounded-xl object-contain"
        loading="lazy"
      />
    </a>
  );
}
