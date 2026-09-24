"use client";

import Link from "next/link";
import type { ReactNode } from "react";

import { openAdminPanel } from "@/lib/admin-panel";

/**
 * A notification's destination — a page of this app, or a page of `/admin`.
 *
 * ⚠️ `/admin` IS NOT A ROUTE OF THIS APP, AND A PLAIN LINK TO IT IS A SECOND
 * PASSWORD PROMPT. The panel is a Laravel session while this app holds a Sanctum
 * token in `localStorage`, which no page request carries (`admin-panel.ts` says
 * why). So a panel destination — «a receipt is waiting» sends the finance
 * officer to that order's page — goes through the handoff ticket, which lands on
 * the exact path asked for. A `<Link>` there would also be a client-side 404:
 * `next.config.ts` rewrites `/api` and `/storage`, never `/admin`.
 *
 * The element is still an `<a href>`, so a middle click or «open in new tab»
 * reaches the panel the ordinary way — at worst through its sign-in page.
 */
export function isPanelUrl(href: string): boolean {
  return href === "/admin" || href.startsWith("/admin/");
}

export function NotificationLink({
  href,
  className,
  onNavigate,
  children,
}: {
  href: string;
  className: string;
  onNavigate?: () => void;
  children: ReactNode;
}) {
  if (!isPanelUrl(href)) {
    return (
      <Link href={href} onClick={onNavigate} className={className}>
        {children}
      </Link>
    );
  }

  return (
    <a
      href={href}
      className={className}
      onClick={(event) => {
        event.preventDefault();
        onNavigate?.();
        void openAdminPanel(href).catch(() => window.location.assign(href));
      }}
    >
      {children}
    </a>
  );
}
