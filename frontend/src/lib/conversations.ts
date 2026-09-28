import { api } from "./api";
import type { Workspace } from "./types";

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
  /** The sender's account photo; null draws their initial instead. */
  sender_avatar_url?: string | null;
  /** The student's guardian wrote this, as the student (2026-09-28). */
  sent_by_guardian?: boolean;
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
  /**
   * The picture or the voice note (`FR-060` · `FR-061`).
   *
   * ⚠️ `url` IS A SHORT-LIVED SIGNATURE, NOT A PERMANENT PATH. It is minted inside
   * a response the reader was already authorised for and lasts fifteen minutes —
   * an `<img>` sends no `Authorization` header, so this is the only shape that
   * works without loading every picture into memory as a blob first. Re-fetch the
   * page to renew it; do not cache it anywhere.
   */
  attachment: {
    kind: "image" | "voice";
    url: string;
    duration_seconds: number | null;
  } | null;
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
  /**
   * The face beside `counterparty_name` — the teacher for a student, the student
   * for the teaching side. Null draws the initial.
   */
  counterparty_avatar_url?: string | null;
  /** Whether THIS reader may hide a message or ban the sender in this thread. */
  can_moderate: boolean;
  /**
   * Whether the discussion is closed right now (`FR-018`).
   *
   * ⚠️ FOR THE COMPOSER AND THE LABEL, NOT FOR THE DOOR. `ConversationPolicy`
   * refuses the write on the request that carries it; this is what stops the
   * student typing a paragraph into a field that will refuse it — the same
   * division `student_banned` already draws.
   */
  is_locked: boolean;
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

/**
 * The open thread telling the list beside it whether its other end is here, as
 * `{ uuid, present }` on `window`. See the messages layout for why only the open
 * thread can say so.
 */
export const CHAT_PRESENCE_CHANGED = "chat-presence:changed";

/**
 * The server received the file and refused what it was.
 *
 * Carries its own sentence rather than the server's `failure_reason`: that field
 * can hold a provider's exception text, and a raw error never reaches the screen.
 */
export class AttachmentRefused extends Error {
  constructor(public readonly kind: "image" | "voice") {
    super(
      kind === "image"
        ? "لم نتمكّن من قبول هذه الصورة. الصيغ المقبولة: PNG وJPEG وWebP."
        : "لم نتمكّن من قبول هذا التسجيل الصوتي. سجّله من جديد وأعد الإرسال.",
    );
    this.name = "AttachmentRefused";
  }
}

/**
 * Send OUR upload URLs through the Next rewrite; leave a provider's alone.
 *
 * ⚠️ THE LOCAL PROVIDER'S TICKET IS AN ABSOLUTE `http://localhost:8000/...`, and
 * fetching it from the browser answers **419**. The whole frontend reaches the
 * API through the same-origin rewrite; an absolute URL steps outside it, the
 * request stops matching what `statefulApi()` expects, and CSRF refuses it. It
 * cost a «حدث خطأ غير متوقّع» on a picture that had uploaded fine by `curl` —
 * because `curl` sends no cookies and no `Origin`, so the one client that proved
 * the endpoint was the one client that could not reproduce the fault.
 *
 * ⚠️ AND IT IS CONDITIONAL, NOT A BLANKET STRIP. A commercial provider signs a
 * genuinely remote URL — that is the entire point of `SC-001`, zero video
 * bandwidth through our own server — and rewriting it to a local path would send
 * the bytes to a route that does not exist. Only a URL whose path is already
 * ours is folded back onto this origin.
 */
function sameOriginIfOurs(url: string): string {
  try {
    const parsed = new URL(url, window.location.origin);

    return parsed.pathname.startsWith("/api/") ? parsed.pathname + parsed.search : url;
  } catch {
    return url;
  }
}

/**
 * What «تواصل مع المدرّس» can do for this reader with one teacher (2026-09-28):
 * one entry per student they may write AS — themselves, or each of a guardian's
 * children. The server derives it from the door's own predicate, so the button
 * never offers what `POST /conversations` would refuse.
 */
export interface ContactOption {
  student_uuid: string;
  /** Null for the reader themself; the child's name for a guardian. */
  student_name: string | null;
  /** A thread that already has messages in it — the button goes straight there. */
  conversation_uuid: string | null;
  /** Whether a (first) message may be sent now. */
  can_start: boolean;
  /** Why not, as a sentence for the reader — never an error. */
  reason: string | null;
  is_subscriber: boolean;
  /** Messages left before the teacher answers; null means no limit. */
  remaining: number | null;
}

export interface ContactOptions {
  options: ContactOption[];
  /** Why there is nothing to offer (a guardian with no linked child, a teacher). */
  note: string | null;
}

/**
 * Where «راسِل» / «تواصل مع المدرّس» takes the reader when no thread exists yet.
 *
 * ⛔ A COMPOSE VIEW, NEVER A NEW EMPTY THREAD (owner decision 2026-09-28). The
 * conversation is created by the first message, so nothing appears in either
 * side's list until somebody has written.
 */
export function composeHref(params: {
  workspace: string;
  student?: string | null;
  name?: string | null;
  remaining?: number | null;
}): string {
  const query = new URLSearchParams({ workspace: params.workspace });

  if (params.student) query.set("student", params.student);
  if (params.name) query.set("name", params.name);
  if (params.remaining !== null && params.remaining !== undefined) {
    query.set("remaining", String(params.remaining));
  }

  return `/messages/new?${query.toString()}`;
}

/**
 * Where one contact option leads: the existing thread, the compose view, or a
 * sentence saying why neither (the teacher does not take new messages, the cap
 * is reached). One spelling for every button that reads the options.
 */
export function contactTarget(
  option: ContactOption,
  workspaceUuid: string,
  name: string,
): { href: string } | { reason: string } {
  if (option.conversation_uuid !== null) return { href: `/messages/${option.conversation_uuid}` };

  if (!option.can_start) {
    return { reason: option.reason ?? "لا يمكن مراسلة هذا المدرّس الآن." };
  }

  return {
    href: composeHref({
      workspace: workspaceUuid,
      // The reader themself needs no `student`; a guardian names the child.
      student: option.student_name === null ? null : option.student_uuid,
      name,
      remaining: option.remaining,
    }),
  };
}

export const conversations = {
  /**
   * Every thread with something in it. `include` keeps the one being opened in
   * the list even while it is empty, so its heading still resolves.
   */
  list: (include?: string) =>
    api.get<{ data: Conversation[] }>(
      `/conversations${include ? `?include=${encodeURIComponent(include)}` : ""}`,
    ),

  /**
   * Which of MY threads have their other end on the platform right now — the
   * list's green dots. Conversation uuids only; the server decides whose status
   * I may see (the other end of my own private threads, nobody else).
   */
  online: () => api.get<{ online: string[] }>("/conversations/online"),

  /**
   * Send the FIRST message to a teacher's side — which opens the one private
   * conversation, or writes into it if it already exists. There is no way to
   * open an empty one.
   */
  start: (workspaceUuid: string, body: string, studentUuid?: string | null) =>
    api.post<Conversation>("/conversations", {
      workspace: workspaceUuid,
      body,
      ...(studentUuid ? { student: studentUuid } : {}),
    }),

  contactOptions: (workspaceUuid: string) =>
    api.get<{ data: ContactOptions }>(
      `/conversations/contact-options?workspace=${encodeURIComponent(workspaceUuid)}`,
    ),

  /**
   * The workspace the teacher's side is acting in — for «راسِل» beside a student.
   *
   * ⚠️ THE WORKSPACE IS THE ONE THE API IS ACTING IN, read from `/workspaces`
   * exactly as the team screen reads it, never assumed from a list the student
   * row came from. The server decides whether this student is theirs
   * (`ConversationPolicy::post()` — an active enrolment in that workspace), so
   * a stranger's uuid is refused at the door rather than filtered here.
   */
  currentWorkspaceUuid: async (): Promise<string> => {
    const workspaces = await api.get<{ data: Workspace[] }>("/workspaces");
    const list = workspaces.data ?? [];
    const current = list.find((w) => w.is_current) ?? list[0];

    if (current === undefined) throw new Error("no workspace");

    return current.uuid;
  },

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

  send: (conversationUuid: string, body: string, attachment?: string) =>
    api.post<ChatMessage>(`/conversations/${conversationUuid}/messages`, {
      body,
      ...(attachment ? { attachment } : {}),
    }),

  /**
   * Put a picture or a voice note somewhere, then hand back its uuid (`FR-060`).
   *
   * ⚠️ THE BYTES NEVER TOUCH THE MESSAGE ENDPOINT. The ticket names a URL owned
   * by whichever provider took the file, the browser PUTs straight to it, and the
   * message that follows carries a uuid and nothing else — so a slow upload keeps
   * the composer responsive and one picture is not sent through PHP twice.
   *
   * ⚠️ AND THE THREE STEPS ARE ONE FUNCTION ON PURPOSE. Ticket, upload, complete:
   * a caller that stopped after the second would leave a `Pending` asset that
   * `PostMessage` refuses, and the sender would see «لم يكتمل رفع المرفق بعد»
   * about a file they watched finish.
   */
  upload: async (
    conversationUuid: string,
    file: Blob,
    kind: "image" | "voice",
    filename: string,
    durationSeconds?: number,
  ): Promise<string> => {
    const ticket = await api.post<{
      asset: { uuid: string };
      upload: { url: string; method: string; headers: Record<string, string> };
    }>(`/conversations/${conversationUuid}/attachments`, {
      kind,
      filename,
      size_bytes: file.size,
      ...(durationSeconds ? { duration_seconds: Math.round(durationSeconds) } : {}),
    });

    const response = await fetch(sameOriginIfOurs(ticket.upload.url), {
      method: ticket.upload.method,
      // The provider's own headers, whatever they are — never a list written
      // here, which would be a second copy of the provider's contract. The
      // file's real type wins over the ticket's default, because the server
      // records what actually arrived.
      headers: { ...ticket.upload.headers, "Content-Type": file.type || "application/octet-stream" },
      body: file,
    });

    if (!response.ok) throw new Error("upload-failed");

    /*
     * ⚠️ THE CHAT'S OWN COMPLETION, NOT `/media/assets/{asset}/complete`. That one
     * is the lesson author's door (`LESSONS_MANAGE`), so every student picture
     * was refused with «لا تملك صلاحية لهذا الإجراء» after it had uploaded.
     *
     * ⚠️ AND ITS ANSWER IS READ. A file that arrived and was refused (a PDF sent
     * as a picture, a recording the server could not prove was sound) is a 200
     * about a FAILED asset — and sending on regardless is what produced «لم يكتمل
     * رفع المرفق بعد» about a voice note the sender had watched finish.
     */
    const settled = await api.post<{ uuid: string; status: string }>(
      `/conversations/${conversationUuid}/attachments/${ticket.asset.uuid}/complete`,
    );

    if (settled.status !== "ready") throw new AttachmentRefused(kind);

    return ticket.asset.uuid;
  },

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
 *
 * ⚠️ AND A PICTURE KEEPS THE LINK IT ALREADY HAS WHILE THAT LINK IS STILL GOOD.
 * The server signs every attachment afresh on every read, and the thread
 * re-reads its newest page on every message that arrives — so taking the new
 * copy whole changed every `src` in the thread each time: every picture
 * re-downloaded (the route answers `no-store`), the shared limiter answered 429
 * within three messages, and a voice note playing at the time stopped. The
 * old link is kept until a minute before its `expires`, then the fresh one
 * takes over.
 */
export function mergeMessages(
  existing: ChatMessage[],
  incoming: ChatMessage[],
  now: number = Date.now(),
): ChatMessage[] {
  const byUuid = new Map<string, ChatMessage>();

  for (const message of existing) {
    byUuid.set(message.uuid, message);
  }

  for (const message of incoming) {
    byUuid.set(message.uuid, keepLiveAttachment(byUuid.get(message.uuid), message, now));
  }

  return [...byUuid.values()].sort((a, b) => {
    const left = a.created_at ?? "";
    const right = b.created_at ?? "";

    // Equal timestamps are the ordinary case — two messages inside one second —
    // so ties keep the order the server sent, which is its monotonic key.
    return left === right ? 0 : left < right ? -1 : 1;
  });
}

/** How long before its expiry a signed link is swapped for the fresh one. */
const LINK_MARGIN_MS = 60_000;

function keepLiveAttachment(previous: ChatMessage | undefined, next: ChatMessage, now: number): ChatMessage {
  const before = previous?.attachment ?? null;
  const after = next.attachment;

  if (before === null || after === null || before.url === after.url || before.kind !== after.kind) return next;

  const expires = signedLinkExpiry(before.url);

  if (expires === null || expires - LINK_MARGIN_MS <= now) return next;

  return { ...next, attachment: { ...after, url: before.url } };
}

/** The `expires` of a Laravel signed link, in milliseconds, or null. */
export function signedLinkExpiry(url: string): number | null {
  try {
    const value = new URL(url, "http://local.invalid").searchParams.get("expires");
    const seconds = value === null ? Number.NaN : Number(value);

    return Number.isFinite(seconds) ? seconds * 1000 : null;
  } catch {
    return null;
  }
}

/**
 * The public room under a session or a lesson (US3).
 *
 * ⚠️ A `GET` THAT MAY CREATE THE ROOM, and that is the server's design: the first
 * person in opens it, idempotently, behind a unique index. A 403 here means the
 * viewer is not entitled to be in the room — the component renders nothing rather
 * than advertising a conversation it must then refuse.
 */
/** The three things a public room can hang off (021 adds the third). */
export type RoomKind = "session" | "lesson" | "cohort";

/*
  A map rather than a chain of ternaries: a fourth kind added to the type above
  is then a compile error here instead of silently falling through to whichever
  branch happens to be last.
*/
const ROOM_PATHS: Record<RoomKind, string> = {
  session: "/class-sessions",
  lesson: "/lessons",
  cohort: "/cohorts",
};

export const rooms = {
  open: (kind: RoomKind, uuid: string) => api.get<Conversation>(`${ROOM_PATHS[kind]}/${uuid}/chat`),

  /** The teacher's endorsement. One press or ten, the points are awarded once. */
  markHelpful: (messageUuid: string) =>
    api.post<ChatMessage>(`/messages/${messageUuid}/helpful`),

  /**
   * Close the discussion, or open it again.
   *
   * Idempotent on the server: pressing «أغلق» twice does not move the timestamp,
   * because a second press is not a second decision.
   */
  setLock: (conversationUuid: string, locked: boolean) =>
    api.post<Conversation>(`/conversations/${conversationUuid}/lock`, { locked }),

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

/**
 * Stop one person writing in ONE thread, for a stated time (021 · FR-047).
 *
 * ⚠️ A THIRD INSTRUMENT, BETWEEN THE TWO THAT ALREADY EXIST. `rooms.setLock`
 * silences a whole class to reach one person; `moderation.ban` covers every
 * thread with that teacher for ever and is recorded as a disciplinary act. This
 * is one thread, one person, with an end — and it is why neither of the other two
 * had to be stretched into a shape it was not built for.
 *
 * `minutes: null` means open, lifted by hand. The reason is mandatory on the
 * server: a silent refusal is read as a fault and retried until the ban lapses.
 */
export const writeBans = {
  set: (conversationUuid: string, userUuid: string, reason: string, minutes: number | null) =>
    api.post<{ message: string }>(`/conversations/${conversationUuid}/write-bans`, {
      user_uuid: userUuid,
      reason,
      ...(minutes === null ? {} : { minutes }),
    }),

  lift: (conversationUuid: string, userUuid: string) =>
    api.delete<{ message: string }>(`/conversations/${conversationUuid}/write-bans`, {
      user_uuid: userUuid,
    }),
};
