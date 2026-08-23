import { api } from "./api";

/**
 * The private chat (spec 010 · US2).
 *
 * ⚠️ THE DATABASE IS THE SOURCE AND THE SOCKET IS AN ACCELERATOR. Every function
 * here is an ordinary HTTP call; `echo.ts` only ever says «something changed, go
 * and fetch it». Reverse that and a reader with no websocket sees an empty chat
 * — `SC-015` says they must not.
 */

export interface ChatMessage {
  uuid: string;
  body: string;
  sender_uuid: string | null;
  sender_name: string | null;
  is_helpful: boolean;
  /**
   * ⚠️ NULL FOR MOST SENDERS, AND NULL IS AN ANSWER. A teacher and an assistant
   * are on no leaderboard at all; a student who joined this morning has no row
   * either, because the boards roll up nightly. Rendered as nothing — a zero
   * would read as «المركز ٠» beside the teacher's own name in front of the class.
   * Present only in a public room.
   */
  sender_rank: number | null;
  sender_level: number | null;
  created_at: string | null;
}

export interface Conversation {
  uuid: string;
  kind: "private" | "session" | "lesson";
  student_name: string | null;
  last_message: {
    uuid: string;
    body: string;
    sender_name: string | null;
    created_at: string | null;
  } | null;
  updated_at: string | null;
}

export const conversations = {
  list: () => api.get<{ data: Conversation[] }>("/conversations"),

  /** Open the one private conversation with a teacher, or return the open one. */
  start: (workspaceUuid: string, studentUuid?: string) =>
    api.post<Conversation>("/conversations", {
      workspace: workspaceUuid,
      ...(studentUuid ? { student: studentUuid } : {}),
    }),

  /**
   * One page, oldest-first.
   *
   * ⚠️ `before` TAKES A MESSAGE UUID AND THERE IS NO `after`. Catching up after a
   * dropped connection re-fetches the NEWEST page rather than asking for
   * everything after a cursor: commit order is not id order on MySQL, so a row
   * committed late can carry a lower id than one already delivered and an
   * `after=` cursor would step straight over it, permanently.
   */
  messages: (conversationUuid: string, before?: string) =>
    api.get<{ data: ChatMessage[] }>(
      `/conversations/${conversationUuid}/messages${before ? `?before=${before}` : ""}`,
    ),

  send: (conversationUuid: string, body: string) =>
    api.post<ChatMessage>(`/conversations/${conversationUuid}/messages`, { body }),

  /** Takes the words back and leaves the row for moderation. */
  hide: (messageUuid: string) => api.delete<ChatMessage>(`/messages/${messageUuid}`),
};

/**
 * Merge whatever just arrived into what is already on screen, by uuid.
 *
 * ⚠️ THE SAME MESSAGE ARRIVES TWICE BY DESIGN — once in the response to the POST
 * that created it, and once again through the socket a moment later. Appending
 * blindly shows the sender their own sentence twice; keyed by uuid, the second
 * copy replaces the first and the list stays in id order because the server
 * returns it that way.
 */
export function mergeMessages(existing: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] {
  const byUuid = new Map<string, ChatMessage>();

  for (const message of [...existing, ...incoming]) {
    byUuid.set(message.uuid, message);
  }

  return [...byUuid.values()].sort((a, b) => {
    const left = a.created_at ?? "";
    const right = b.created_at ?? "";

    // Equal timestamps are the ordinary case — two messages inside one second —
    // so ties keep the order the server sent, which is its monotonic key.
    return left === right ? 0 : left < right ? -1 : 1;
  });
}

/**
 * The public room under a session or a lesson (US3).
 *
 * ⚠️ A `GET` THAT MAY CREATE THE ROOM, and that is the server's design: the first
 * person in opens it, idempotently, behind a unique index. A 403 here means the
 * viewer is not entitled to be in the room — the component renders nothing rather
 * than advertising a conversation it must then refuse.
 */
export const rooms = {
  open: (kind: "session" | "lesson", uuid: string) =>
    api.get<Conversation>(
      kind === "session" ? `/class-sessions/${uuid}/chat` : `/lessons/${uuid}/chat`,
    ),

  /** The teacher's endorsement. One press or ten, the points are awarded once. */
  markHelpful: (messageUuid: string) =>
    api.post<ChatMessage>(`/messages/${messageUuid}/helpful`),

  /** The human path for what the term list did not catch. */
  report: (messageUuid: string, reason?: string) =>
    api.post<{ message: string }>(`/messages/${messageUuid}/report`, reason ? { reason } : {}),
};
