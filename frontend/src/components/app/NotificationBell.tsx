"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { BellIcon } from "@/components/icons";
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
 */
export function NotificationBell() {
  const [count, setCount] = useState(0);

  useEffect(() => {
    let cancelled = false;

    notifications
      .unreadCount()
      .then(({ unread_count }) => {
        if (!cancelled) setCount(unread_count);
      })
      .catch(() => {
        if (!cancelled) setCount(0);
      });

    return () => {
      cancelled = true;
    };
  }, []);

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
