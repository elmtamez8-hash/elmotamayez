import type { Metadata } from "next";

import { StartRedirect } from "./StartRedirect";

/**
 * Where the INSTALLED app opens (`start_url` in `app/manifest.ts`).
 *
 * ⛔ Signed in → wherever signing in sends that person (`homePathFor()`: the
 * marketplace for a student or guardian, `/dashboard` for a teacher or staff);
 * a visitor → `/` (owner decision 2026-09-27). The
 * manifest used to open `/dashboard` for everybody, so a visitor who installed
 * the app from the marketplace landed on a sign-in wall instead of the page they
 * installed it from.
 *
 * ⚠️ A ROUTE OF ITS OWN, NOT A CHECK ON THE HOME PAGE. The token lives in
 * `localStorage`, so only the browser can answer «signed in?» — and `/` is
 * rendered on the server for search engines, so it would paint the marketing page
 * first and jump to the dashboard after: the flash of wrong content the decision
 * ruled out. This page paints nothing but the wait, then replaces itself.
 *
 * ⚠️ OFFLINE IS UNCHANGED: the service worker answers a failed navigation — this
 * one included — with the precached `/offline` page.
 */
export const metadata: Metadata = {
  robots: { index: false, follow: false },
};

export default function StartPage() {
  return <StartRedirect />;
}
