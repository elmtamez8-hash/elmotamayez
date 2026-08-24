"use client";

import { useParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";

import { ChatHeader } from "@/components/community/ChatHeader";
import { Composer } from "@/components/community/Composer";
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
import { listen } from "@/lib/echo";
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
  const [moderating, setModerating] = useState(false);
  const [olderExhausted, setOlderExhausted] = useState(false);

  const bottom = useRef<HTMLDivElement | null>(null);

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

  const send = () => {
    const body = draft.trim();

    if (body === "") return;

    setSending(true);
    setProblem(null);
    setBodyError(undefined);

    conversations
      .send(uuid, body)
      .then((message) => {
        // Merged rather than appended: the same message arrives again through
        // the socket a moment later.
        setMessages((current) => mergeMessages(current, [message]));
        setDraft("");

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
        onChange={setDraft}
        onSend={send}
        disabled={sending}
        error={bodyError}
      />
    </div>
  );
}
