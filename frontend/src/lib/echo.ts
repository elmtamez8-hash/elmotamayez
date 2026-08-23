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

let client: EchoClient | null = null;
let attempted = false;

function config(): { key: string; host: string; port: number; scheme: string } | null {
  const key = process.env.NEXT_PUBLIC_REVERB_APP_KEY;

  // No key configured is a deployment without live delivery, not an error. The
  // pages fall back to fetching, which is what they do during an outage anyway.
  if (!key) return null;

  return {
    key,
    host: process.env.NEXT_PUBLIC_REVERB_HOST ?? window.location.hostname,
    port: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
    scheme: process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "http",
  };
}

/**
 * The shared connection, or `null` when there is none to be had.
 *
 * Callers must handle `null`. That is not defensive clutter: it is the same code
 * path an outage takes, so the branch is exercised in development every time
 * somebody forgets to start `reverb:start`.
 */
export async function echo(): Promise<EchoClient | null> {
  if (client) return client;
  if (attempted) return null;
  if (typeof window === "undefined") return null;

  attempted = true;

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

    client = new EchoConstructor({
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

    return client;
  } catch {
    // A missing library, a blocked port, a refused upgrade — all of them mean the
    // same thing to a caller, and all of them leave the page working.
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

  connection.private(channel).listen(`.${event}`, handler);

  return () => {
    connection.leave(`private-${channel}`);
  };
}
