"use client";

import { usePathname } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ChatSoundToggle } from "@/components/community/ChatSoundToggle";
import { ConversationList } from "@/components/community/ConversationList";
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
export default function MessagesLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { user } = useAuth();

  // `/messages` → the list is the screen. `/messages/{uuid}` → the thread is.
  const openUuid = pathname.startsWith("/messages/") ? pathname.slice("/messages/".length) : null;

  const [rows, setRows] = useState<Conversation[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);

  const load = useCallback(() => {
    setState("loading");
    setProblem(null);

    conversations
      .list()
      .then((response) => {
        setRows(response.data ?? []);
        setState("ready");
      })
      .catch((error: unknown) => {
        // Never a swallowed reason: the answer is in the response, and a blank
        // sidebar with nothing said is the defect this repository already paid
        // for once on the lesson screen.
        setProblem(userMessage(error));
        setState("error");
      });
  }, []);

  useEffect(load, [load]);

  /*
   * Which rows get the green dot — the open thread, when its other end is there.
   *
   * ⚠️ ONLY THE OPEN THREAD, AND THAT IS THE HONEST LIMIT. The dot is fed by the
   * thread's presence channel, which says who has THAT thread open; joining one
   * per row would be two hundred subscriptions and two hundred authorisations on
   * one page load to answer a narrower question than a dot suggests. The page
   * announces its own count through a window event, the pattern
   * `conversations:changed` already uses across this route boundary.
   */
  const [online, setOnline] = useState<ReadonlySet<string>>(new Set());

  useEffect(() => {
    const onPresence = (event: Event) => {
      const { uuid, present } = (event as CustomEvent<{ uuid: string; present: boolean }>).detail;

      setOnline((current) => {
        if (current.has(uuid) === present) return current;

        const next = new Set(current);

        if (present) next.add(uuid);
        else next.delete(uuid);

        return next;
      });
    };

    window.addEventListener(CHAT_PRESENCE_CHANGED, onPresence);

    return () => window.removeEventListener(CHAT_PRESENCE_CHANGED, onPresence);
  }, []);

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
      listen(`user.${uuid}`, "message.posted", load)
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
  }, [user?.uuid, load]);

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
     */
    <div className="-m-4 flex h-[calc(100dvh-4rem)] overflow-hidden sm:-m-6">
      <aside
        className={
          // On a phone the sidebar IS the screen until a thread is open; from
          // `md` up it is a fixed column beside it.
          (openUuid === null ? "flex" : "hidden") +
          " min-h-0 w-full shrink-0 flex-col border-e border-line md:flex md:w-80"
        }
      >
        <div className="flex shrink-0 items-center justify-end border-b border-line px-3 py-1">
          <ChatSoundToggle />
        </div>

        <div className="min-h-0 flex-1">
          {state === "loading" && <RowsSkeleton count={5} />}

          {state === "error" && (
            <ErrorState onRetry={load} description={problem ?? undefined} />
          )}

          {state === "ready" && (
            <ConversationList rows={rows} activeUuid={openUuid} onlineUuids={online} />
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
