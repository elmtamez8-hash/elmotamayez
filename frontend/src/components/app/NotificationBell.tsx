"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { BellIcon } from "@/components/icons";
import { useAuth } from "@/lib/auth-context";
import { listen } from "@/lib/echo";
import { notifications } from "@/lib/notifications";

/**
 * The unread badge in the panel header.
 *
 * Calls /notifications/unread-count rather than the feed: it renders on every
 * page, and pulling twenty notifications to display one number would put the
 * whole payload on every navigation.
 *
 * A failed count renders as no badge. A header that cannot count is not worth
 * an error banner — the notifications page will say so if it is also down.
 *
 * It also polls, and that second job is why the interval exists at all: the bell
 * is on every panel page, so this is the request that discovers a session ended
 * from another device even when the user is doing nothing — reading a page,
 * watching a video, or away from the keyboard. The 401 handler in lib/api.ts
 * does the rest. Without it, a signed-out account keeps showing its owner a
 * screen they are no longer entitled to until they next click something.
 *
 * ⚠️ THE SOCKET ACCELERATES THE COUNT AND DOES NOT REPLACE THE POLL. This file
 * used to say spec 010 «replaces this with a socket», and doing so would have
 * removed the session heartbeat described above with it: a websocket that drops
 * is a client that learns nothing, which is the exact state an evicted account is
 * already in. So the interval stays at sixty seconds and is still the thing that
 * discovers an ended session; `user.{uuid}` only means the badge moves in a
 * second instead of within a minute.
 *
 * ⚠️ AND IT COUNTS BY ASKING THE SERVER, NEVER BY INCREMENTING ON A FRAME. The
 * payload carries an identifier and no notification, the reader may have the feed
 * open in another tab, and a locally-incremented badge drifts from the truth on
 * the first message read somewhere else — the database is the source here for the
 * same reason it is in the chat.
 */
const POLL_SECONDS = 60;

export function NotificationBell() {
  const [count, setCount] = useState(0);
  const { user } = useAuth();

  useEffect(() => {
    let cancelled = false;

    const poll = () => {
      notifications
        .unreadCount()
        .then(({ unread_count }) => {
          if (!cancelled) setCount(unread_count);
        })
        .catch(() => {
          if (!cancelled) setCount(0);
        });
    };

    poll();
    const timer = window.setInterval(poll, POLL_SECONDS * 1000);

    let unsubscribe: (() => void) | null = null;
    const uuid = user?.uuid;

    if (uuid !== undefined && uuid !== null) {
      listen(`user.${uuid}`, "message.posted", poll)
        .then((off) => {
          if (cancelled) {
            off();

            return;
          }

          unsubscribe = off;
        })
        .catch(() => undefined);
    }

    return () => {
      cancelled = true;
      window.clearInterval(timer);
      unsubscribe?.();
    };
  }, [user?.uuid]);

  const label = count > 0 ? `الإشعارات، ${count} غير مقروء` : "الإشعارات";

  return (
    <Link
      href="/notifications"
      aria-label={label}
      className="relative rounded-lg p-2 text-ink transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
    >
      <BellIcon />
      {count > 0 && (
        <span
          aria-hidden="true"
          // `-end-0.5`, not `-right-0.5`: the badge belongs on the far edge of
          // the icon, which is the left one in RTL.
          className="absolute -top-0.5 -end-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white"
        >
          {count > 99 ? "٩٩+" : count.toLocaleString("ar-EG")}
        </span>
      )}
    </Link>
  );
}
