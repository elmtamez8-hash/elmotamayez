"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { MessageList } from "@/components/community/MessageList";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextareaField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import {
  conversations,
  mergeMessages,
  rooms,
  type ChatMessage,
  type Conversation,
} from "@/lib/conversations";
import { listen } from "@/lib/echo";
import { userMessage } from "@/lib/errors";

/**
 * The public room under a session or a lesson (spec 010 · US3).
 *
 * ⚠️ IT RENDERS NOTHING WHEN THE VIEWER IS NOT ENTITLED, and that is the design.
 * The room is for the people in the lesson; a student with no seat gets a 403
 * from `GET .../chat`, and a banner telling them there is a conversation they may
 * not read is worse than the absence — it advertises a room and refuses it in one
 * breath. Every other failure becomes a sentence through `userMessage()`.
 *
 * ⚠️ AND THE RANK IS ABSENT FOR MOST SENDERS, WHICH MUST NOT BREAK THE LINE. A
 * teacher and an assistant are on no board at all, and a student who joined this
 * morning has no row either. `null` renders as nothing — never «المركز ٠», and
 * never an empty badge with a dash in it.
 */
export function SessionChat({
  kind,
  uuid,
  title = "نقاش الحصّة",
}: {
  kind: "session" | "lesson";
  uuid: string;
  title?: string;
}) {
  const { user } = useAuth();

  const [room, setRoom] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "closed">("loading");
  const [problem, setProblem] = useState<string | null>(null);
  const [bodyError, setBodyError] = useState<string | undefined>(undefined);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);

  // Guards the double tap: a second press while the first is in flight would
  // post the same sentence twice, and no server-side claim can tell them apart.
  const inFlight = useRef(false);

  const refresh = useCallback((conversationUuid: string) => {
    conversations
      .messages(conversationUuid)
      .then((response) => {
        setMessages((current) => mergeMessages(current, response.data ?? []));
        setProblem(null);
      })
      .catch((error: unknown) => setProblem(userMessage(error)));
  }, []);

  useEffect(() => {
    let cancelled = false;

    rooms
      .open(kind, uuid)
      .then((conversation) => {
        if (cancelled) return;

        setRoom(conversation);
        setState("ready");
        refresh(conversation.uuid);
      })
      .catch(() => {
        // No sentence and no banner: somebody who may not be here is told
        // nothing about a room they cannot open.
        if (!cancelled) setState("closed");
      });

    return () => {
      cancelled = true;
    };
  }, [kind, uuid, refresh]);

  useEffect(() => {
    if (room === null) return;

    let cancelled = false;
    let unsubscribe: (() => void) | null = null;

    listen(`conversation.${room.uuid}`, "message.posted", () => refresh(room.uuid))
      .then((off) => {
        if (cancelled) {
          off();

          return;
        }

        unsubscribe = off;
      })
      // A socket that will not open changes nothing about what this can do: the
      // room still reads and still sends, one reload behind.
      .catch(() => undefined);

    return () => {
      cancelled = true;
      unsubscribe?.();
    };
  }, [room, refresh]);

  if (state === "loading" || state === "closed" || room === null) return null;

  const send = () => {
    const body = draft.trim();

    if (body === "" || inFlight.current) return;

    inFlight.current = true;
    setSending(true);
    setProblem(null);
    setBodyError(undefined);

    conversations
      .send(room.uuid, body)
      .then((message) => {
        // Merged, never appended: the socket delivers the same message again a
        // moment later.
        setMessages((current) => mergeMessages(current, [message]));
        setDraft("");
      })
      .catch((error: unknown) => {
        const fields = fieldErrors(error);

        // A refusal by the term list is a 422 about the WORDS — it lands under
        // the field, beside what the sender still has in the box.
        if (fields.body) {
          setBodyError(fields.body);
        } else {
          setProblem(userMessage(error));
        }
      })
      .finally(() => {
        inFlight.current = false;
        setSending(false);
      });
  };

  return (
    <Card>
      <h2 className="mb-3 font-medium text-ink">{title}</h2>

      {problem !== null && (
        <Alert tone="danger" title="تعذّر إتمام الطلب">
          {problem}
        </Alert>
      )}

      <MessageList messages={messages} currentUserUuid={user?.uuid ?? null} showBadges />

      <div className="mt-4">
        <TextareaField
          id="room-body"
          label="شارك في النقاش"
          value={draft}
          onChange={setDraft}
          rows={2}
          error={bodyError}
          disabled={sending}
          placeholder="اكتب سؤالك أو إجابتك…"
        />

        <div className="mt-3 flex justify-end">
          <Button onClick={send} disabled={sending || draft.trim() === ""}>
            إرسال
          </Button>
        </div>
      </div>
    </Card>
  );
}
