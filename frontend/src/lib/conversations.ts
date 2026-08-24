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
  student_uuid: string | null;
  /**
   * ⚠️ THE TITLE OF THE ROW, AND `student_name` IS NOT IT. That field is the
   * counterpart for the TEACHER and the reader's own name for the student — so a
   * list built on it titled every row on a student's screen with their own name.
   * Null in a public room, which is a class rather than a person.
   */
  counterparty_name: string | null;
  /** Whether THIS reader may hide a message or ban the sender in this thread. */
  can_moderate: boolean;
  /**
   * Whether the student is banned from writing right now.
   *
   * ⚠️ FOR THE LABEL ON THE CONTROL, NOT FOR THE DOOR. Whether a message is
   * accepted is decided by `BanReader` inside the policy, on the request that
   * writes it; this is as old as the page. Without it the moderator saw «احظر»
   * beside somebody they had banned a minute earlier, because the button tracked
   * only what they had done since the last reload.
   */
  student_banned: boolean;
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

/**
 * Hiding, banning, lifting (spec 010 · `FR-064`).
 *
 * ⚠️ NONE OF THIS IS NEW WORK ON THE SERVER, AND THAT IS THE POINT. The endpoint,
 * the Action, the append-only table and its tests all shipped with the moderation
 * phase — and a `grep` for a caller across `src/` returned nothing at all, so the
 * whole surface was reachable only by typing a request by hand. A permission
 * classified by absence passes every test it has while guarding nothing; a
 * FEATURE classified by absence is one nobody can use.
 *
 * ⚠️ AND LIFTING A BAN IS A NEW ROW, NEVER A DELETE. `ModerationAction` throws on
 * `updating` and `deleting`, and `BanReader` takes the most recent row: asking
 * «does a ban row exist» would keep somebody banned for ever after they were
 * forgiven, and «does an unban row exist» would free somebody banned a second
 * time.
 */
export const moderation = {
  ban: (studentUuid: string, reason?: string, expiresAt?: string) =>
    api.post<{ uuid: string }>("/moderation/actions", {
      verdict: "banned",
      subject_type: "user",
      subject_uuid: studentUuid,
      ...(reason ? { reason } : {}),
      ...(expiresAt ? { expires_at: expiresAt } : {}),
    }),

  unban: (studentUuid: string, reason?: string) =>
    api.post<{ uuid: string }>("/moderation/actions", {
      verdict: "unbanned",
      subject_type: "user",
      subject_uuid: studentUuid,
      ...(reason ? { reason } : {}),
    }),
};
