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
  const [notice, setNotice] = useState<string | null>(null);
  const [bodyError, setBodyError] = useState<string | undefined>(undefined);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [locking, setLocking] = useState(false);

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

  /** The teacher's endorsement. One press or ten, the points are awarded once. */
  const markHelpful = (messageUuid: string) => {
    setProblem(null);

    rooms
      .markHelpful(messageUuid)
      .then((updated) => setMessages((current) => mergeMessages(current, [updated])))
      .catch((error: unknown) => setProblem(userMessage(error)));
  };

  /**
   * The room comes back from the server rather than being flipped locally: the
   * lock is idempotent there, so a second press must render what the server
   * believes and not what this tab guessed.
   */
  const toggleLock = () => {
    setProblem(null);
    setLocking(true);

    rooms
      .setLock(room.uuid, !room.is_locked)
      .then((updated) => setRoom(updated))
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setLocking(false));
  };

  const report = (messageUuid: string) => {
    setProblem(null);

    rooms
      .report(messageUuid)
      // The answer is a constant sentence carrying no uuid and no count — a
      // reporter learning whether theirs was the first is an oracle over
      // somebody else's record.
      .then((response) => setNotice(response.message))
      .catch((error: unknown) => setProblem(userMessage(error)));
  };

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
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-medium text-ink">{title}</h2>

        {/* The teacher's valve. Offered only where there is a class to quiet —
            a private thread has no «الجميع», and silencing one person there is
            a BAN: declared, recorded and appealable. The server refuses it. */}
        {room.can_moderate && room.kind !== "private" && (
          <Button variant="ghost" loading={locking} onClick={toggleLock}>
            {room.is_locked ? "افتح النقاش" : "أغلق النقاش"}
          </Button>
        )}
      </div>

      {problem !== null && (
        <Alert tone="danger" title="تعذّر إتمام الطلب">
          {problem}
        </Alert>
      )}

      {notice !== null && (
        <Alert tone="info" title="تمّ">
          {notice}
        </Alert>
      )}

      {/*
        ⚠️ THE TWO HANDLERS ARE THE WHOLE OF `FR-064` HERE, AND BOTH ENDPOINTS
        SHIPPED WITH THE MODERATION PHASE WITH NO CALLER AT ALL. `markHelpful`
        awards the twenty points and ten coins spec 009 defined for an endorsed
        answer, and a `grep` for it across `src/` returned nothing — so the whole
        gamification loop it feeds was unreachable, and every test of it passed
        against a path no person could take.

        `canEndorse` is `can_moderate` and not a second condition: endorsing is a
        teacher-side act in a room, and the server answers that question once.
      */}
      <MessageList
        messages={messages}
        currentUserUuid={user?.uuid ?? null}
        showBadges
        onReport={report}
        onMarkHelpful={room.can_moderate ? markHelpful : undefined}
      />

      {/*
        ⚠️ THE COMPOSER IS SHUT FOR EVERYONE EXCEPT WHOEVER MAY REOPEN IT. A
        teacher who closes the discussion and finds their own field disabled
        cannot answer the last question on screen, and cannot say why they closed
        it — so the person holding the key keeps writing. The server draws the
        same line: `chat.moderate` is the exemption inside the policy.
      */}
      {room.is_locked && !room.can_moderate ? (
        <Alert tone="info" title="النقاش مغلق مؤقّتاً">
          أغلق المدرّس المشاركة الآن. يمكنك متابعة القراءة، وستُفتح مرّةً أخرى.
        </Alert>
      ) : (
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
      )}
    </Card>
  );
}
