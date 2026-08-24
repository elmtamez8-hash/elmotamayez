"use client";

import { useParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";

import { ChatHeader } from "@/components/community/ChatHeader";
import { Composer, type PendingAttachment } from "@/components/community/Composer";
import { MessageList } from "@/components/community/MessageList";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import {
  conversations,
  mergeMessages,
  moderation,
  rooms,
  type ChatMessage,
  type Conversation,
} from "@/lib/conversations";
import { join, listen } from "@/lib/echo";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";

/**
 * One thread (spec 010 · US2 · `FR-054`).
 *
 * ⚠️ EVERYTHING WORKS WITH THE SOCKET SWITCHED OFF. The page fetches on mount,
 * the send returns the saved message, and `listen()` resolves to a no-op
 * unsubscribe when there is no connection to be had — so a reader with no
 * websocket sees a chat that is one reload behind rather than a blank screen.
 * That is `SC-015`, measured for real on 2026-08-23 by killing `reverb` mid
 * session, and it is why nothing here awaits a connection before rendering.
 *
 * ⚠️ AND THE SOCKET DELIVERS AN IDENTIFIER, NEVER A BODY. The handler re-fetches
 * the newest page through the authenticated route: the payload carries no words,
 * so a reader whose permission was withdrawn mid-connection is refused at that
 * fetch instead of being served the message on a channel nobody can revoke.
 *
 * ⚠️ AND CATCHING UP RE-FETCHES THE NEWEST PAGE rather than asking for everything
 * after a cursor. Commit order is not id order on MySQL, so a row committed late
 * can carry a lower id than one already delivered — an `after=` cursor would step
 * over it and never come back.
 */
export default function ConversationPage() {
  const params = useParams<{ uuid: string }>();
  const uuid = params.uuid;

  const { user } = useAuth();

  const [thread, setThread] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [bodyError, setBodyError] = useState<string | undefined>(undefined);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [banned, setBanned] = useState(false);
  /** How many OTHER people have this thread open right now. Never stored. */
  const [present, setPresent] = useState(0);
  const [typing, setTyping] = useState<string | null>(null);
  const [whisper, setWhisper] = useState<(() => void) | null>(null);
  const [pending, setPending] = useState<PendingAttachment | null>(null);
  const [moderating, setModerating] = useState(false);
  const [olderExhausted, setOlderExhausted] = useState(false);

  const bottom = useRef<HTMLDivElement | null>(null);
  const lastWhisper = useRef(0);

  /*
   * ⚠️ AT MOST ONE WHISPER EVERY TWO SECONDS. One per keystroke is one FRAME per
   * keystroke — Reverb's own rate limiter would begin dropping them mid-sentence,
   * and the indicator on the other side would flicker rather than hold. Two
   * seconds is comfortably inside the three-second expiry on the receiving end, so
   * a continuous typist never appears to stop.
   */
  const announceTyping = () => {
    const now = Date.now();

    if (whisper === null || now - lastWhisper.current < 2000) return;

    lastWhisper.current = now;
    whisper();
  };

  const refresh = useCallback(
    (mode: "initial" | "catch-up") => {
      if (mode === "initial") setState("loading");

      conversations
        .messages(uuid)
        .then((response) => {
          setMessages((current) => mergeMessages(current, response.data ?? []));
          setState("ready");
          setProblem(null);
        })
        .catch((error: unknown) => {
          setProblem(userMessage(error));

          // A failed catch-up leaves what is already on screen: the thread is
          // still true, it is merely not the newest. Only the first load has
          // nothing to fall back on.
          if (mode === "initial") setState("error");
        });
    },
    [uuid],
  );

  useEffect(() => refresh("initial"), [refresh]);

  /*
   * The thread's own row, for the title and the moderation control.
   *
   * ⚠️ TAKEN FROM THE LIST RATHER THAN FROM A SECOND ENDPOINT. `GET /conversations`
   * already answers who the counterpart is and whether this reader may moderate,
   * and the layout above has just fetched it — a `GET /conversations/{uuid}` built
   * for this heading would be a second place for «who am I talking to» to be
   * answered, and the two would disagree the first time one of them changed.
   */
  useEffect(() => {
    let cancelled = false;

    conversations
      .list()
      .then((response) => {
        if (cancelled) return;

        setThread((response.data ?? []).find((row) => row.uuid === uuid) ?? null);
      })
      .catch(() => {
        // The heading falls back to a neutral word; the thread itself is already
        // readable and a refusal banner over a working chat helps nobody.
        if (!cancelled) setThread(null);
      });

    return () => {
      cancelled = true;
    };
  }, [uuid]);

  useEffect(() => {
    let cancelled = false;
    let unsubscribe: (() => void) | null = null;

    listen(`conversation.${uuid}`, "message.posted", () => refresh("catch-up"))
      .then((off) => {
        if (cancelled) {
          off();

          return;
        }

        unsubscribe = off;
      })
      // ⚠️ A socket that cannot be opened is not an error a person can act on,
      // and it changes nothing about what the page can do. Reported to the
      // console for the developer who forgot `reverb:start`, and no further.
      .catch(() => undefined);

    return () => {
      cancelled = true;
      unsubscribe?.();
    };
  }, [uuid, refresh]);

  useEffect(() => {
    bottom.current?.scrollIntoView({ block: "end" });
  }, [messages]);

  /*
   * Who else is here, and who is typing (`FR-058` · `FR-059`).
   *
   * ⚠️ THE OTHER SIDE IS «ANYONE WHO IS NOT ME», NOT A NAMED PERSON. A private
   * thread has two ends, but the teacher's end may be answered by an assistant —
   * so a presence check written against the counterpart's uuid would show
   * «غير متصل» while the person actually replying is right there.
   *
   * ⚠️ AND THE TYPING FLAG EXPIRES ON A TIMER, because a whisper has no «stopped»
   * event and no delivery guarantee. Somebody who types one letter and closes the
   * tab would otherwise be typing for ever on the other person's screen.
   */
  useEffect(() => {
    let cancelled = false;
    let release: (() => void) | null = null;
    let typingTimer: number | undefined;

    const dropTyping = () => {
      window.clearTimeout(typingTimer);
      typingTimer = window.setTimeout(() => setTyping(null), 3000);
    };

    join(`chat-presence.${uuid}`, {
      here: (members) => setPresent(members.filter((m) => m.uuid !== user?.uuid).length),
      joining: (member) => {
        if (member.uuid !== user?.uuid) setPresent((n) => n + 1);
      },
      leaving: (member) => {
        if (member.uuid !== user?.uuid) setPresent((n) => Math.max(0, n - 1));
      },
      typing: (member) => {
        if (member.uuid === user?.uuid) return;

        setTyping(member.name);
        dropTyping();
      },
    })
      .then((room) => {
        if (cancelled) {
          room.release();

          return;
        }

        release = room.release;
        setWhisper(() => room.whisper);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
      window.clearTimeout(typingTimer);
      release?.();
    };
  }, [uuid, user?.uuid]);

  const send = () => {
    const body = draft.trim();
    const attachment = pending;

    // ⚠️ EITHER IS ENOUGH NOW. A voice note has no words, and refusing to send
    // one because the box is empty was the shape of the first `422` the server
    // answered about a file that had already finished uploading.
    if (body === "" && attachment === null) return;

    setSending(true);
    setProblem(null);
    setBodyError(undefined);

    /*
     * ⚠️ THE UPLOAD RUNS FIRST AND ITS FAILURE IS THE SEND'S FAILURE. Posting the
     * message first would put an empty bubble in the thread while the bytes were
     * still moving, and nothing could remove it if they never arrived.
     */
    const uploaded = attachment === null
      ? Promise.resolve(undefined)
      : conversations.upload(
          uuid,
          attachment.file,
          attachment.kind,
          attachment.kind === "voice" ? "voice-note" : "photo",
          attachment.kind === "voice" ? attachment.seconds : undefined,
        );

    uploaded
      .then((assetUuid) => conversations.send(uuid, body, assetUuid))
      .then((message) => {
        // Merged rather than appended: the same message arrives again through
        // the socket a moment later.
        setMessages((current) => mergeMessages(current, [message]));
        setDraft("");
        // Frees the blob URL the preview was holding.
        if (attachment !== null) URL.revokeObjectURL(attachment.preview);
        setPending(null);

        // ⚠️ THE SENDER IS NOT A RECIPIENT OF THEIR OWN MESSAGE, so no frame
        // reaches `user.{uuid}` here and the sidebar preview would stay on the
        // previous sentence until a reload. See the layout's own note.
        window.dispatchEvent(new CustomEvent("conversations:changed"));
      })
      .catch((error: unknown) => {
        const fields = fieldErrors(error);

        if (fields.body) {
          setBodyError(fields.body);
        } else {
          setProblem(userMessage(error));
        }
      })
      .finally(() => setSending(false));
  };

  const loadOlder = () => {
    const oldest = messages[0];

    if (!oldest) return;

    conversations
      .messages(uuid, oldest.uuid)
      .then((response) => {
        const older = response.data ?? [];

        setOlderExhausted(older.length === 0);
        setMessages((current) => mergeMessages(older, current));
      })
      .catch((error: unknown) => setProblem(userMessage(error)));
  };

  /*
   * ⚠️ THE BUTTON TRACKS LOCAL STATE, AND `banned` IS NOT READ BACK FROM THE
   * SERVER. There is no endpoint that answers «is this person banned» — the ban
   * is consulted inside `ConversationPolicy::post()` and nowhere else — so this
   * flag reflects what THIS reader has just done, which is what the label needs
   * to say next. Building a status endpoint for it would be a second answer to a
   * question the door already answers; a moderator who wants the history has the
   * append-only record.
   */
  const toggleBan = () => {
    const studentUuid = thread?.student_uuid;

    if (studentUuid === null || studentUuid === undefined) return;

    setModerating(true);
    setProblem(null);
    setNotice(null);

    const call = banned ? moderation.unban(studentUuid) : moderation.ban(studentUuid);

    call
      .then(() => {
        setBanned((was) => !was);
        setNotice(banned ? "رُفع الحظر، وعاد بإمكانه الكتابة." : "حُظرت الكتابة عن حسابه في مساحتك.");
      })
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setModerating(false));
  };

  const report = (messageUuid: string) => {
    setProblem(null);

    rooms
      .report(messageUuid)
      .then((response) => setNotice(response.message))
      .catch((error: unknown) => setProblem(userMessage(error)));
  };

  if (state === "loading") return <RowsSkeleton count={5} />;
  if (state === "error") {
    return <ErrorState onRetry={() => refresh("initial")} description={problem ?? undefined} />;
  }

  return (
    <div className="flex h-full min-w-0 flex-1 flex-col">
      <ChatHeader
        title={thread?.counterparty_name ?? "المحادثة"}
        // ⚠️ «يكتب…» OUTRANKS «متصل الآن», because it implies it and says more.
        // Showing both stacks two lines of status under a two-word name.
        subtitle={typing !== null ? "يكتب…" : present > 0 ? "متصل الآن" : null}
        canModerate={thread?.can_moderate ?? false}
        banned={banned}
        onBanToggle={toggleBan}
        busy={moderating}
      />

      {problem !== null && (
        <div className="p-3">
          <Alert tone="danger" title="تعذّر إتمام الطلب">
            {problem}
          </Alert>
        </div>
      )}

      {notice !== null && (
        <div className="p-3">
          <Alert tone="info" title="تمّ">
            {notice}
          </Alert>
        </div>
      )}

      <div className="flex-1 overflow-y-auto">
        {messages.length > 0 && !olderExhausted && (
          <div className="p-3 text-center">
            <Button variant="secondary" onClick={loadOlder}>
              الرسائل الأقدم
            </Button>
          </div>
        )}

        <MessageList
          messages={messages}
          currentUserUuid={user?.uuid ?? null}
          onReport={report}
          onHide={(messageUuid) => {
            conversations
              .hide(messageUuid)
              .then(() => setMessages((current) => current.filter((m) => m.uuid !== messageUuid)))
              .catch((error: unknown) => setProblem(userMessage(error)));
          }}
        />

        <div ref={bottom} />
      </div>

      <Composer
        value={draft}
        onChange={(next) => {
          setDraft(next);
          announceTyping();
        }}
        onSend={send}
        disabled={sending}
        error={bodyError}
        pending={pending}
        onAttachmentChange={setPending}
      />
    </div>
  );
}
