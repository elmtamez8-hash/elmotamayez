"use client";

import { useRef } from "react";

import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { VoiceNotePlayer } from "@/components/community/VoiceNotePlayer";
import { useChatScroll } from "@/lib/chat-scroll";
import { counted, formatDate, formatTime, NOUNS } from "@/lib/labels";
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
  onSilence,
  showBadges = false,
  showAvatars = false,
  size = "compact",
  onLoadOlder,
}: {
  messages: ChatMessage[];
  currentUserUuid: string | null;
  onHide?: (uuid: string) => void;
  /** The human path for what the term list did not catch (`FR-064`). */
  onReport?: (uuid: string) => void;
  /** The teacher's endorsement — public rooms only. */
  onMarkHelpful?: (uuid: string) => void;
  /**
   * Stop this sender writing in this thread (021 · FR-047).
   *
   * ⚠️ IT TAKES THE SENDER, NOT THE MESSAGE. Hiding is about one sentence and
   * silencing is about one person — passing a message uuid here would make the
   * caller resolve it back to a sender, which is a lookup for something this row
   * already knows.
   */
  onSilence?: (senderUuid: string, senderName: string) => void;
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
  /**
   * The sender's face at the head of each incoming run, WhatsApp-style. Off in a
   * room, where thirty faces down one column is noise; on in a private thread.
   */
  showAvatars?: boolean;
  /**
   * `fill` takes whatever height its flex parent gives it (the full-screen
   * thread); `compact` is a fixed-height box under a lesson's stage — the room's
   * chat sits under the stage, the controls and the participants, and forty
   * messages there used to push the composer a whole screen down.
   */
  size?: "fill" | "compact";
  /** Present when there is an older page to ask for. */
  onLoadOlder?: () => void;
}) {
  const box = useRef<HTMLDivElement>(null);
  const content = useRef<HTMLUListElement>(null);

  const last = messages[messages.length - 1];

  /*
    ⚠️ THIS IS THE ONLY SCROLL BOX, AND THERE WERE TWO. The thread page wrapped
    this list in an `overflow-y-auto` of its own while the list carried
    `max-h-80 overflow-y-auto` for the room — so the open conversation showed two
    vertical scrollbars, one inside the other, and the page's `scrollIntoView`
    moved the outer one while new messages landed in the inner. The box lives
    here now and its SIZE is the host's choice; following the reader, the
    «رسائل جديدة» pill and the older-page offset are one hook for both hosts.
  */
  const { unseen, jumpToLatest } = useChatScroll(
    box,
    content,
    messages.map((message) => message.uuid),
    last !== undefined && currentUserUuid !== null && last.sender_uuid === currentUserUuid,
  );

  return (
    <div className={size === "fill" ? "relative flex min-h-0 flex-1 flex-col" : "relative"}>
      {/*
        `overscroll-contain` so reaching the top of the thread does not start
        scrolling the page behind it; `overflow-anchor: none` because the hook
        corrects the offset itself when an older page arrives, and the browser's
        own scroll anchoring doing it as well would move the reader twice.
      */}
      <div
        ref={box}
        data-testid="chat-scroll"
        className={
          (size === "fill" ? "min-h-0 flex-1" : "max-h-80") +
          " overflow-y-auto overscroll-contain [overflow-anchor:none]"
        }
      >
        {onLoadOlder !== undefined && messages.length > 0 && (
          <div className="p-3 text-center">
            <Button variant="secondary" onClick={onLoadOlder}>
              الرسائل الأقدم
            </Button>
          </div>
        )}

        {messages.length === 0 ? (
          <p className="py-8 text-center text-sm text-ink-muted">
            لا رسائل بعد. اكتب أوّل رسالة في الأسفل.
          </p>
        ) : (
          <ul ref={content} className="space-y-1 px-3 py-4">
            {messages.map((message, index) => {
              const mine = currentUserUuid !== null && message.sender_uuid === currentUserUuid;
              const previous = index === 0 ? null : messages[index - 1];
              const newDay = startsNewDay(previous, message);
              const headOfRun = showsSender(previous, message);
              // The face sits beside the FIRST bubble of a run and a same-width gap
              // beside the rest, so a run reads as one person speaking.
              const withFace = !mine && showAvatars;

              return (
                <li key={message.uuid}>
                  {newDay && (
                    <div className="my-4 flex justify-center">
                      <span className="rounded-full bg-surface px-3 py-1 text-[11px] text-ink-muted">
                        {formatDate(message.created_at)}
                      </span>
                    </div>
                  )}

                  <div
                    data-testid="chat-message"
                    className={
                      "group " +
                      (mine ? "ms-auto max-w-[85%]" : "me-auto max-w-[85%]") +
                      (withFace ? " flex items-start gap-2" : "")
                    }
                  >
                    {withFace &&
                      (headOfRun ? (
                        <Avatar url={message.sender_avatar_url ?? null} name={message.sender_name ?? "؟"} size="sm" />
                      ) : (
                        <span aria-hidden="true" className="w-8 shrink-0" />
                      ))}

                    <div className={withFace ? "min-w-0 flex-1" : undefined}>
                      {/* The sender's name only on the other side, and only when it
                          changes — repeating it on every consecutive bubble is noise in
                          a two-person thread and clutter in a room. */}
                      {!mine && headOfRun && (
                        <div className="mb-1 flex flex-wrap items-center gap-2 px-1 text-xs text-ink-muted">
                          <span className="font-medium">{message.sender_name ?? "—"}</span>

                          {/* A guardian writes AS the child, in the child's thread
                              (2026-09-28) — the teacher must not read the parent's words
                              as the student's. Stored at send time on the server. */}
                          {message.sent_by_guardian === true && <Badge tone="warning">وليّ الأمر</Badge>}

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
                            /*
                              ⚠️ `surface`, NOT `surface-raised` — WHICH IS THE CARD THIS
                              SITS ON. Both resolved to the same colour in both themes, so
                              everybody ELSE's messages were unstyled text floating on the
                              card while mine were clearly bubbled. In a live lesson the
                              teacher's answers are the ones that disappear.
                            */
                            : "rounded-2xl rounded-es-sm bg-surface px-3 py-2 text-ink"
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
                            <span className={mine ? "text-[11px] text-white/80" : "text-[11px] text-secondary-ink"}>
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

                        {!mine && onSilence && message.sender_uuid !== null && (
                          <button
                            type="button"
                            onClick={() => onSilence(message.sender_uuid as string, message.sender_name ?? "هذا الطالب")}
                            className="text-danger-ink underline"
                          >
                            أوقف كتابته
                          </button>
                        )}

                        {!mine && onMarkHelpful && !message.is_helpful && (
                          <button type="button" onClick={() => onMarkHelpful(message.uuid)} className="text-secondary-ink underline">
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
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {/*
        A new message arrived while the reader was up in the history. They are
        told, not moved — and one press takes them down to it.
      */}
      {unseen && (
        <div className="pointer-events-none absolute inset-x-0 bottom-3 flex justify-center">
          <button
            type="button"
            onClick={jumpToLatest}
            className="pointer-events-auto rounded-full bg-primary px-4 py-1.5 text-xs font-semibold text-white shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            رسائل جديدة ↓
          </button>
        </div>
      )}
    </div>
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
 * ⚠️ A SMALL PLAYER OF OUR OWN, NOT `<audio controls>`. The browser's controls
 * read the length from the file, and a Chrome-recorded WebM has none until it is
 * played through — every note showed «0:00 / 0:00». `VoiceNotePlayer` falls back
 * to the server's `duration_seconds`. It is still not the lesson player, which
 * exists for HLS, watermarks and grant renewal; none of that applies here.
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
        <VoiceNotePlayer url={attachment.url} durationSeconds={attachment.duration_seconds} mine={mine} />
        {attachment.duration_seconds !== null && (
          <span className={mine ? "text-[10px] text-white/70" : "text-[10px] text-ink-muted"}>
            <bdi>{counted(attachment.duration_seconds, { ...NOUNS.seconds, zero: "أقل من ثانية" })}</bdi>
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
