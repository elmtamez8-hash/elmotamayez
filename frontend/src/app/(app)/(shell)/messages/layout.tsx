"use client";

import { usePathname } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { ChatSoundToggle } from "@/components/community/ChatSoundToggle";
import { ConversationList } from "@/components/community/ConversationList";
import { MessagesIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { CHAT_PRESENCE_CHANGED, conversations, type Conversation } from "@/lib/conversations";
import { listen } from "@/lib/echo";
import { userMessage } from "@/lib/errors";

/**
 * The two panes (spec 010 · `FR-054` · `FR-055`).
 *
 * ⚠️ A LAYOUT RATHER THAN A PAGE, BECAUSE BOTH ROUTES HAVE TO SURVIVE. Every
 * notification this product has ever sent about a message carries
 * `action_url = /messages/{uuid}`, so a redesign that folded the thread into the
 * list screen as a piece of state would break every one of them — including the
 * ones already delivered and sitting unread in someone's feed. Two routes, one
 * layout: the URL still names the thread, and the sidebar is chrome around it.
 *
 * ⚠️ AND THE LIST IS FETCHED ONCE, HERE. A layout does not re-mount when the
 * child route changes, so moving between threads costs no second request and the
 * sidebar does not blink — which is the whole reason the list lives at this level
 * rather than inside each page.
 *
 * The mobile rule is one pane at a time and it is expressed in CSS, not in a
 * media-query hook: `hidden md:block` renders both on the server and lets the
 * viewport decide, so there is no first paint with the wrong pane in it.
 */
/** How often the list re-asks who is online while the tab is visible. */
const ONLINE_REFRESH_MS = 30_000;

export default function MessagesLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { user } = useAuth();

  // `/messages` → the list is the screen. `/messages/{uuid}` → the thread is.
  const openUuid = pathname.startsWith("/messages/") ? pathname.slice("/messages/".length) : null;

  /*
   * ⚠️ `null` MEANS «NOT LOADED YET», AND ONLY THAT SHOWS THE SKELETON. The list
   * is re-read on every message that arrives and every one the reader sends; the
   * first version set a `loading` state on each of those, so the sidebar blinked
   * grey rows in and out on every line of the conversation (live two-account
   * test, 2026-09-28). A refresh now keeps the rows on screen and swaps the new
   * ones in when they come — and a refresh that FAILS keeps them too, with a
   * line saying the list may be behind, because ten true threads are better than
   * an error box where they were.
   */
  const [rows, setRows] = useState<Conversation[] | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  // Two refreshes in flight answer in any order; only the newest may land.
  const latest = useRef(0);

  const load = useCallback(() => {
    const request = ++latest.current;

    conversations
      .list()
      .then((response) => {
        if (request !== latest.current) return;

        setRows(response.data ?? []);
        setProblem(null);
      })
      .catch((error: unknown) => {
        if (request !== latest.current) return;

        // Never a swallowed reason: the answer is in the response, and a blank
        // sidebar with nothing said is the defect this repository already paid
        // for once on the lesson screen.
        setProblem(userMessage(error));
      });
  }, []);

  // The retry from the error box: back to the skeleton, since there is nothing
  // else to show while it asks.
  const retry = useCallback(() => {
    setProblem(null);
    load();
  }, [load]);

  useEffect(load, [load]);

  /*
   * Which rows get the green dot: the other end is ON THE PLATFORM right now
   * (owner decision, 2026-09-28 — «متصل الآن» like WhatsApp).
   *
   * ⚠️ ASKED OF THE SERVER, NOT JOINED ON THE SOCKET. `GET /conversations/online`
   * answers for the other end of MY OWN threads and nobody else, from Reverb's
   * own record of who holds their `user.{uuid}` channel. A presence channel per
   * row would be two hundred subscriptions and two hundred authorisations per
   * page for a busy teacher; a presence channel per person that counterparts
   * join would show each student the other students talking to that teacher.
   *
   * Refreshed every 30 seconds while the tab is visible, at once when it becomes
   * visible again, and whenever a message arrives (someone who just wrote is
   * online). The open thread's own presence (`chat-presence.{uuid}`) is merged
   * in live, so the row the reader is looking at never lags its header.
   */
  const [platformOnline, setPlatformOnline] = useState<ReadonlySet<string>>(new Set());
  const [threadPresence, setThreadPresence] = useState<{ uuid: string; present: boolean } | null>(null);

  const refreshOnline = useCallback(() => {
    conversations
      .online()
      .then((response) => setPlatformOnline(new Set(response.online ?? [])))
      // A dot that cannot be fetched is a dot that stays dark — not a banner
      // over a list that is otherwise working.
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    refreshOnline();

    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") refreshOnline();
    }, ONLINE_REFRESH_MS);

    const onVisible = () => {
      if (document.visibilityState === "visible") refreshOnline();
    };

    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("conversations:changed", refreshOnline);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("conversations:changed", refreshOnline);
    };
  }, [refreshOnline]);

  useEffect(() => {
    const onPresence = (event: Event) =>
      setThreadPresence((event as CustomEvent<{ uuid: string; present: boolean }>).detail);

    window.addEventListener(CHAT_PRESENCE_CHANGED, onPresence);

    return () => window.removeEventListener(CHAT_PRESENCE_CHANGED, onPresence);
  }, []);

  const onlineRows = useMemo(() => {
    if (threadPresence === null || !threadPresence.present) return platformOnline;

    return new Set([...platformOnline, threadPresence.uuid]);
  }, [platformOnline, threadPresence]);

  /*
   * The sidebar, live (`FR-054`).
   *
   * ⚠️ `user.{uuid}` AND NOT THE CONVERSATION CHANNEL. This pane shows threads the
   * reader is NOT looking at, and a subscription per row would mean two hundred
   * private channels and two hundred authorisation requests on one page load.
   * `MessagePosted` already publishes to one channel per recipient for exactly
   * this — it was defined in the first broadcast phase and nothing had ever
   * subscribed to it, so the list sat still until a refresh.
   *
   * ⚠️ AND THE SENDER IS NOT A RECIPIENT OF THEIR OWN MESSAGE, by design — which
   * would leave a preview stale on the one screen whose owner just caused the
   * change. `conversations:changed` is the composer saying so directly: a window
   * event rather than shared state, because the two live on opposite sides of a
   * route boundary and a context spanning them would exist for this one line.
   */
  useEffect(() => {
    let cancelled = false;
    let unsubscribe: (() => void) | null = null;

    const uuid = user?.uuid;

    if (uuid !== undefined && uuid !== null) {
      // Somebody who just wrote is online: the dots are re-asked with the list.
      listen(`user.${uuid}`, "message.posted", () => {
        load();
        refreshOnline();
      })
        .then((off) => {
          if (cancelled) {
            off();

            return;
          }

          unsubscribe = off;
        })
        // ⚠️ THE ONE SANCTIONED SWALLOW, AND ONLY BECAUSE OF WHAT FAILED. A socket
        // that will not open changes nothing this list can do — the conversations
        // are read from the API and `conversations:changed` still refreshes them,
        // one beat later. Everything else on this screen goes through
        // `userMessage()`; swallowing a FETCH here would render an empty list to
        // somebody who has ten threads.
        .catch(() => undefined);
    }

    window.addEventListener("conversations:changed", load);

    return () => {
      cancelled = true;
      unsubscribe?.();
      window.removeEventListener("conversations:changed", load);
    };
  }, [user?.uuid, load, refreshOnline]);

  return (
    /*
     * ⚠️ `-m-6` CANCELS THE SHELL'S OWN PADDING, AND `4rem` IS ITS HEADER. Both
     * numbers come from `(shell)/layout.tsx` — `<main className="p-6">` under a
     * `h-16` sticky header — and a chat is the one screen that wants neither: a
     * message list inset by 24px on a 390px phone loses an eighth of the line
     * width to nothing, and the padding is also what pushed the composer below
     * the fold on the first attempt, because the height was subtracting the
     * header alone.
     *
     * `100dvh` and not `100vh`: on a phone the second is the viewport WITHOUT the
     * browser's own collapsing chrome, so the send button sits under the address
     * bar exactly while somebody is typing.
     */
    /*
     * ⚠️ AND THE NEGATIVE MARGIN FOLLOWS THE SHELL'S PADDING AT EVERY WIDTH. The
     * shell's `<main>` is `p-4 sm:p-6`; a flat `-m-6` overshot the phone's 16px by
     * 8px on each side, which is a horizontal scrollbar and a page that scrolls
     * behind the thread.
     *
     * From `md` up the bleed is dropped and the chat is a card like every other
     * screen (`7rem` = the header plus the shell's `p-6` above and below): the
     * phone argument above is about a 390px line, and a desk has the room.
     */
    <div className="-m-4 flex h-[calc(100dvh-4rem)] overflow-hidden bg-surface-raised sm:-m-6 md:m-0 md:h-[calc(100dvh-7rem)] md:rounded-3xl md:border md:border-line md:shadow-sm">
      <aside
        className={
          // On a phone the sidebar IS the screen until a thread is open; from
          // `md` up it is a fixed column beside it.
          (openUuid === null ? "flex" : "hidden") +
          " min-h-0 w-full shrink-0 flex-col border-e border-line bg-surface md:flex md:w-80 xl:w-96"
        }
      >
        <div className="flex shrink-0 items-center justify-between gap-2 px-4 pb-1 pt-4">
          <h2 className="flex items-center gap-3 text-lg font-extrabold text-ink">
            <span
              aria-hidden="true"
              className="grid h-10 w-10 place-items-center rounded-xl bg-primary text-white shadow-md shadow-primary/20"
            >
              <MessagesIcon />
            </span>
            المحادثات
          </h2>
          <ChatSoundToggle />
        </div>

        <div className="min-h-0 flex-1">
          {rows === null && problem === null && <RowsSkeleton count={5} />}

          {rows === null && problem !== null && <ErrorState onRetry={retry} description={problem} />}

          {rows !== null && (
            <div className="flex h-full flex-col">
              {problem !== null && (
                <div className="shrink-0 p-2">
                  <Alert tone="warning" title="تعذّر تحديث المحادثات، وقد تكون القائمة متأخرة.">
                    {problem}
                  </Alert>
                </div>
              )}

              <div className="min-h-0 flex-1">
                <ConversationList rows={rows} activeUuid={openUuid} onlineUuids={onlineRows} />
              </div>
            </div>
          )}
        </div>
      </aside>

      {/* `min-h-0`: the thread's message box is the only thing that scrolls, and
          a flex child without it grows to its content instead. */}
      <main className={(openUuid === null ? "hidden" : "flex") + " min-h-0 min-w-0 flex-1 md:flex"}>
        {children}
      </main>
    </div>
  );
}
