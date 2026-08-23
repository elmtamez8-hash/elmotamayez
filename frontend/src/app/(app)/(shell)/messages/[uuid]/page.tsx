"use client";

import { useParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";

import { MessageList } from "@/components/community/MessageList";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextareaField } from "@/components/ui/Field";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { conversations, mergeMessages, type ChatMessage } from "@/lib/conversations";
import { listen } from "@/lib/echo";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";

/**
 * One thread (spec 010 · US2).
 *
 * ⚠️ EVERYTHING WORKS WITH THE SOCKET SWITCHED OFF. The page fetches on mount,
 * the send returns the saved message, and `listen()` resolves to a no-op
 * unsubscribe when there is no connection to be had — so a reader with no
 * websocket sees a chat that is one reload behind rather than a blank screen.
 * That is `SC-015`, and it is why nothing here awaits a connection before
 * rendering.
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

  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);
  const [bodyError, setBodyError] = useState<string | undefined>(undefined);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
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

  if (state === "loading") return <RowsSkeleton count={5} />;
  if (state === "error") return <ErrorState onRetry={() => refresh("initial")} description={problem ?? undefined} />;

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <h1 className="text-xl font-semibold text-ink">المحادثة</h1>

      {problem !== null && (
        <Alert tone="danger" title="تعذّر إتمام الطلب">
          {problem}
        </Alert>
      )}

      <Card>
        {messages.length > 0 && !olderExhausted && (
          <div className="mb-3 text-center">
            <Button variant="secondary" onClick={loadOlder}>
              الرسائل الأقدم
            </Button>
          </div>
        )}

        <MessageList
          messages={messages}
          currentUserUuid={user?.uuid ?? null}
          onHide={(messageUuid) => {
            conversations
              .hide(messageUuid)
              .then(() => setMessages((current) => current.filter((m) => m.uuid !== messageUuid)))
              .catch((error: unknown) => setProblem(userMessage(error)));
          }}
        />

        <div ref={bottom} />
      </Card>

      <Card>
        <TextareaField
          id="body"
          label="رسالتك"
          value={draft}
          onChange={setDraft}
          rows={3}
          error={bodyError}
          disabled={sending}
          placeholder="اكتب رسالتك هنا…"
        />

        <div className="mt-3 flex justify-end">
          <Button onClick={send} disabled={sending || draft.trim() === ""}>
            إرسال
          </Button>
        </div>
      </Card>
    </div>
  );
}
