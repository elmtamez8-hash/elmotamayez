import type Echo from "laravel-echo";

/**
 * The websocket, if there is one (spec 010 · US2).
 *
 * ⚠️ LAZY, AND THE LAZINESS IS THE REQUIREMENT. Nothing here runs at import time:
 * the library is loaded dynamically, the connection is opened the first time a
 * screen asks for it, and every failure resolves to `null` rather than throwing.
 * A chat page must render, fetch and send with no socket at all — that is
 * `SC-015`, and a module-level `new Echo(...)` would make a failed connection a
 * blank page instead.
 *
 * ⚠️ AND IT SPEAKS THE PUSHER PROTOCOL, WHICH IS WHAT REVERB IS. `pusher-js` is
 * the client; the host and key come from `NEXT_PUBLIC_*` because the browser
 * needs them, and neither is a secret — `REVERB_APP_SECRET` is the one that signs
 * and it never leaves the server.
 */

const TOKEN_KEY = "auth_token";

type EchoClient = Echo<"reverb">;

/*
| ⚠️ THE PROMISE IS MEMOISED, NOT A BOOLEAN «attempted» FLAG. That flag shipped
| and was wrong the moment a SECOND screen asked for the socket at the same time:
| the first caller set it, started the async import, and every caller that
| arrived before the import finished was answered `null` — permanently, because
| the flag never cleared. It was invisible while one page used the socket, and
| broke the day the notification bell and the conversation list both wanted it,
| which is every page at once. Measured 2026-08-24: connection `connected`,
| channels `[]`.
|
| A memoised promise makes every caller await the SAME construction, whenever
| they arrive.
*/
let clientPromise: Promise<EchoClient | null> | null = null;

/*
| ⚠️ AND THE SUBSCRIPTIONS ARE COUNTED, BECAUSE `leave()` IS PER CHANNEL AND NOT
| PER LISTENER. The bell and the sidebar both listen on `user.{uuid}` — the same
| channel by design — so the first of them to unmount used to tear the channel
| out from under the other, silently. React's development double-invoke does the
| same thing on a single mount, which is how this reaches a page with only one
| listener on it.
*/
const holders = new Map<string, number>();

function config(): { key: string; host: string; port: number; scheme: string } | null {
  const key = process.env.NEXT_PUBLIC_REVERB_APP_KEY;

  // No key configured is a deployment without live delivery, not an error. The
  // pages fall back to fetching, which is what they do during an outage anyway.
  if (!key) return null;

  /*
  | ⚠️ BOTH FALLBACKS FOLLOW THE PAGE, AND THE SCHEME ONE USED TO BE THE LITERAL
  | «http». That is unreachable from an https page at all: a browser refuses a
  | `ws://` socket opened by a secure page as mixed content, silently, with the
  | chat still reading and sending one reload behind — which is exactly the
  | shape of «‏النقاش لا يظهر لحظيّاً» and names nothing.
  |
  | The host fallback was already right and was being overridden by a literal
  | `localhost` in `.env` — measured on a phone (2026-08-26): the page loaded
  | from `192.168.1.13:3000` and the socket dialled the PHONE's own localhost.
  | A host written down once is a host that is wrong for every other address the
  | same build is opened from.
  |
  | The env vars stay for production, where the socket sits behind a proxy on a
  | different host and port from the page.
  */
  return {
    key,
    host: process.env.NEXT_PUBLIC_REVERB_HOST ?? window.location.hostname,
    port: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
    scheme:
      process.env.NEXT_PUBLIC_REVERB_SCHEME ??
      (window.location.protocol === "https:" ? "https" : "http"),
  };
}

/**
 * The shared connection, or `null` when there is none to be had.
 *
 * Callers must handle `null`. That is not defensive clutter: it is the same code
 * path an outage takes, so the branch is exercised in development every time
 * somebody forgets to start `reverb:start`.
 */
export function echo(): Promise<EchoClient | null> {
  if (typeof window === "undefined") return Promise.resolve(null);

  clientPromise ??= create();

  return clientPromise;
}

async function create(): Promise<EchoClient | null> {
  const settings = config();

  if (!settings) return null;

  try {
    const [{ default: EchoConstructor }, { default: Pusher }] = await Promise.all([
      import("laravel-echo"),
      import("pusher-js"),
    ]);

    // laravel-echo reads the client off the global, which is how its reverb
    // connector finds pusher-js without importing it itself.
    (window as unknown as { Pusher: unknown }).Pusher = Pusher;

    return new EchoConstructor({
      broadcaster: "reverb",
      key: settings.key,
      wsHost: settings.host,
      wsPort: settings.port,
      wssPort: settings.port,
      forceTLS: settings.scheme === "https",
      enabledTransports: ["ws", "wss"],
      /*
       * ⚠️ THE SAME ORIGIN AND A BEARER HEADER. The auth route is registered under
       * `/api` so the Next rewrite carries it to the backend — without the prefix
       * this leaves the origin and fails CORS — and the token lives in
       * `localStorage`, so a cookie-based default would identify nobody and every
       * private subscription would be refused.
       */
      authEndpoint: "/api/broadcasting/auth",
      auth: {
        headers: {
          Authorization: `Bearer ${window.localStorage.getItem(TOKEN_KEY) ?? ""}`,
        },
      },
    }) as EchoClient;
  } catch (reason) {
    // A missing library, a blocked port, a refused upgrade — all of them mean the
    // same thing to a caller, and all of them leave the page working.
    console.error("[echo] socket unavailable", reason);

    return null;
  }
}

/**
 * Listen on one private channel and hand back an unsubscribe.
 *
 * The callback receives an IDENTIFIER and nothing else — the payload carries no
 * message body on purpose (see `MessagePosted`), so the handler's job is to go
 * and fetch through the authenticated route.
 */
export async function listen(
  channel: string,
  event: string,
  handler: (payload: { message_uuid: string; conversation_uuid: string }) => void,
): Promise<() => void> {
  const connection = await echo();

  if (!connection) return () => {};

  /*
   * ⚠️ THE LEADING DOT IS LOAD-BEARING. Without it Echo prepends the application
   * namespace and listens for `App\Modules\Community\Events\MessagePosted`, while
   * the server sends what `broadcastAs()` returns — `message.posted`. The
   * subscription succeeds, the frames arrive, and the handler never fires.
   */
  /*
   * ⚠️ WRAPPED SO EVERY SUBSCRIPTION HAS ITS OWN IDENTITY. pusher-js unbinds BY
   * FUNCTION REFERENCE and removes every entry matching it — so two `listen()`
   * calls that pass the same function (a `useCallback` with empty deps is exactly
   * that, and React's development double-invoke produces two of them from one
   * mount) bind twice and are BOTH removed by the first cleanup. Measured on
   * 2026-08-24: the frame arrived on the wire, the channel was subscribed, and
   * one of the two listeners on it had already been unbound — so the sidebar sat
   * still while the notification bell beside it updated from the same frame.
   */
  const bound = (payload: { message_uuid: string; conversation_uuid: string }) => handler(payload);

  connection.private(channel).listen(`.${event}`, bound);
  holders.set(channel, (holders.get(channel) ?? 0) + 1);

  let released = false;

  return () => {
    // Idempotent: React can run a cleanup more than once, and a second release
    // decrementing the count again would leave the channel while a live listener
    // still holds it.
    if (released) return;

    released = true;

    /*
     * ⚠️ `stopListening` FIRST AND `leave` ONLY AT ZERO. `leave()` drops the whole
     * channel — every handler on it, not just this one — and the bell and the
     * sidebar deliberately share `user.{uuid}`. Whichever unmounted first used to
     * silence the other with nothing said anywhere.
     */
    connection.private(channel).stopListening(`.${event}`, bound);

    const left = (holders.get(channel) ?? 1) - 1;

    if (left > 0) {
      holders.set(channel, left);

      return;
    }

    holders.delete(channel);

    // `leave()` takes the BARE name and drops the private and presence variants
    // with it; `leaveChannel()` is the one that wants the prefix.
    connection.leave(channel);
  };
}

/** Somebody else in the room. Name and uuid only — see `routes/channels.php`. */
export type ChatMember = { uuid: string; name: string };

/**
 * Join the presence channel for one thread (`FR-058` · `FR-059`).
 *
 * ⚠️ PRESENCE, NOT A SECOND PRIVATE CHANNEL, AND THE PROTOCOL DECIDED THAT. Reverb
 * accepts client events only `from: members`, so a whisper — which is what a
 * typing indicator has to be if it is not to write a row per keystroke — is
 * refused on a private channel and accepted here. The same subscription answers
 * «who is watching this thread», so one channel carries both features.
 *
 * ⚠️ AND NOTHING IS PERSISTED, DELIBERATELY. `FR-058` forbids a «last seen»
 * column: what is never written cannot be exported under a data request, cannot
 * be retained past its purpose, and cannot become a record of when a child was
 * awake. The member list lives in the connection and dies with it.
 *
 * Returns an unsubscribe and a `whisper` — null when there is no socket, which is
 * the same branch an outage takes and the reason every caller must handle it.
 */
export async function join(
  channel: string,
  handlers: {
    here: (members: ChatMember[]) => void;
    joining: (member: ChatMember) => void;
    leaving: (member: ChatMember) => void;
    typing: (member: ChatMember) => void;
  },
): Promise<{ release: () => void; whisper: (() => void) | null }> {
  const connection = await echo();

  if (!connection) return { release: () => {}, whisper: null };

  const room = connection.join(channel);

  room
    .here(handlers.here)
    .joining(handlers.joining)
    .leaving(handlers.leaving)
    // The name is ours and travels only between clients — the server neither
    // stores it nor rebroadcasts it, which is what a whisper IS.
    .listenForWhisper("typing", handlers.typing);

  holders.set(channel, (holders.get(channel) ?? 0) + 1);

  let released = false;

  return {
    release: () => {
      if (released) return;

      released = true;

      const left = (holders.get(channel) ?? 1) - 1;

      if (left > 0) {
        holders.set(channel, left);

        return;
      }

      holders.delete(channel);
      connection.leave(channel);
    },
    /*
     * `whisper` on the channel object, not on Echo: it is addressed to the other
     * members of THIS room.
     *
     * ⚠️ AND IT CARRIES THE SENDER'S OWN MEMBER INFO, because a whisper does NOT.
     * The first version sent `{}` — the frame arrived, the handler ran, and the
     * receiver had no uuid to compare against its own and no name to show, so
     * every whisper both bypassed the «is this me» filter and rendered a nameless
     * indicator. The server never sees a whisper at all, so there is nothing to
     * stamp it: the identity has to be in the payload, taken from the membership
     * the channel already authorised.
     */
    whisper: () => {
      // `members` is pusher-js's own bookkeeping and is absent from Echo's
      // published type; the cast reaches it without widening the channel to any.
      const me = (room as unknown as { members?: { me?: { info?: ChatMember } } })
        .members?.me?.info;

      if (me === undefined) return;

      room.whisper("typing", me);
    },
  };
}
