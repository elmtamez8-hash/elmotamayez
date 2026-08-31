/**
 * The product is «المتميز» — PRODUCT.md records it as a binding brand
 * commitment, and the wordmark in every header spells it.
 *
 * ⚠️ THE NAME IS A `platform_settings` ROW NOW, NOT AN ENVIRONMENT VARIABLE, AND
 * THE OLD SHAPE IS WHY THE LIVE SITE SAID «منصّتي» FOR MONTHS.
 * `NEXT_PUBLIC_PLATFORM_NAME` is inlined at BUILD time by Next, so the name could
 * only change by rebuilding — and it was set nowhere on the server, so every
 * `<title>`, every Open Graph tag and the footer fell through to a placeholder
 * with nothing failing anywhere. Measured on the live site, 2026-08-31:
 * «منصّتي — مدرّسون خصوصيون بالعربية».
 *
 * `GET /api/v1/platform` answers it at RUN time from the row an operator edits at
 * `/admin/platform-settings`, so a change takes effect without a deploy.
 *
 * ⚠️ AND `PLATFORM_NAME` WAS DELETED RATHER THAN REPOINTED. Removing the old
 * export is what makes `tsc` walk every one of its fifty-six call sites — the
 * technique `PasswordField` and `NumberField` already used in this codebase. A
 * constant left in place beside the new reader is a second spelling that keeps
 * being reached for.
 */

/**
 * What the product is called when the API cannot say.
 *
 * ⚠️ THE REAL NAME, NEVER A PLACEHOLDER. A fallback that reads «منصّتي» is a
 * fallback nobody notices is in use — which is the entire history of this file.
 * If the API is unreachable the page should still look like the product.
 */
export const PLATFORM_NAME_FALLBACK = "المتميز";

/**
 * The product's name, read on the server.
 *
 * ⚠️ AN ABSOLUTE URL, BECAUSE A SERVER-SIDE `fetch` IGNORES THE REWRITE.
 * `next.config.ts` maps `/api/*` onto the backend for the BROWSER; a fetch that
 * runs in Node has no origin to resolve `/api/v1/platform` against and throws.
 * `API_ORIGIN` is the same variable the rewrite's destination reads, so there is
 * one answer to «where is the API» rather than two that drift.
 *
 * ⚠️ AND IT NEVER THROWS. `next build` runs in a container where the API is not
 * up, and again on the VPS mid-deploy — so a rejection here would kill every
 * build. It degrades to the fallback instead, which bakes the real name into a
 * statically rendered page and lets the next revalidation correct it.
 *
 * ⚠️ `revalidate` KEEPS THE MARKETING PAGES STATIC. Without it every page that
 * reads the name becomes dynamic, and the landing page would be rendered per
 * visitor for a string that changes once a year. The cost is stated plainly: a
 * rename takes up to five minutes to reach a static page.
 */
export async function platformName(): Promise<string> {
  const origin = process.env.API_ORIGIN ?? "http://localhost:8000";

  try {
    const response = await fetch(`${origin}/api/v1/platform`, {
      headers: { Accept: "application/json" },
      next: { revalidate: 300, tags: ["platform-identity"] },
    });

    if (!response.ok) return PLATFORM_NAME_FALLBACK;

    const body = (await response.json()) as { data?: { name?: unknown } };
    const name = body.data?.name;

    // A blank row is a row somebody cleared by accident; it must not blank the
    // title of every page on the site.
    return typeof name === "string" && name.trim() !== "" ? name.trim() : PLATFORM_NAME_FALLBACK;
  } catch {
    return PLATFORM_NAME_FALLBACK;
  }
}

export const CURRENCY = "QAR";
export const CURRENCY_LABEL = "ر.ق";

/** Reference timezone for teaching hours; the UI converts to the visitor's zone. */
export const PLATFORM_TIMEZONE = "Asia/Qatar";

/**
 * Support number in E.164 without the leading "+", e.g. 97455512345.
 *
 * Empty by default and the floating button does not render without it. There is
 * no sensible placeholder: a wa.me link with a made-up number opens a stranger's
 * chat, and every visitor who taps it is sent to a real person who did not sign
 * up for it. Set NEXT_PUBLIC_WHATSAPP_NUMBER to turn the button on.
 */
export const SUPPORT_WHATSAPP = (
  process.env.NEXT_PUBLIC_WHATSAPP_NUMBER ?? ""
).replace(/[^\d]/g, "");
