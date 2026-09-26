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
 *
 * ⚠️ `SUPPORT_WHATSAPP` WAS THE SAME DEFECT, STILL LIVE, AND IT LEFT THE SAME
 * WAY. It read `NEXT_PUBLIC_WHATSAPP_NUMBER`, empty in every environment
 * including `.env.example`, so the floating button and the footer link rendered
 * for nobody — and NOTHING FAILED, because an empty number is also how that
 * button is switched OFF on purpose. The bug and the correct configuration had
 * exactly the same shape. It is `platform.support_whatsapp` on the same endpoint
 * now, and the constant was deleted rather than repointed so `tsc` named both of
 * its readers.
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
export type PlatformIdentity = {
  name: string;
  /** Digits only, E.164 without the leading "+". Empty means no support line. */
  supportWhatsapp: string;
  /*
   * Who stands behind the platform, for the legal pages (2026-09-26). Each is
   * an empty string when unset, and every reader drops its line then.
   */
  legalName: string;
  postalAddress: string;
  contactEmail: string;
};

/** What the product is, when the API cannot say. */
const IDENTITY_FALLBACK: PlatformIdentity = {
  name: PLATFORM_NAME_FALLBACK,
  /*
   * ⚠️ EMPTY, AND NEVER A GUESS. A `wa.me` link with an invented number opens a
   * stranger's chat, and every visitor who taps it reaches a real person who
   * never agreed to it. An unreachable API must switch the button OFF, not
   * point it somewhere.
   */
  supportWhatsapp: "",
  legalName: "",
  postalAddress: "",
  contactEmail: "",
};

/**
 * The product's public identity, read on the server.
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
 *
 * ⚠️ ONE FETCH FOR BOTH FIELDS, AND THAT IS WHY IT IS ONE FUNCTION. Next dedupes
 * identical requests within a render, so `platformName()` below costs nothing
 * extra — but two functions each doing their own `fetch` with their own cache
 * tag would be two answers to «what is this platform» that revalidate apart.
 */
export async function platformIdentity(): Promise<PlatformIdentity> {
  const origin = process.env.API_ORIGIN ?? "http://localhost:8000";

  try {
    const response = await fetch(`${origin}/api/v1/platform`, {
      headers: { Accept: "application/json" },
      next: { revalidate: 300, tags: ["platform-identity"] },
    });

    if (!response.ok) return IDENTITY_FALLBACK;

    const body = (await response.json()) as {
      data?: {
        name?: unknown;
        support_whatsapp?: unknown;
        legal_name?: unknown;
        postal_address?: unknown;
        contact_email?: unknown;
      };
    };
    const text = (value: unknown): string => (typeof value === "string" ? value.trim() : "");
    const name = body.data?.name;
    const whatsapp = body.data?.support_whatsapp;

    return {
      // A blank row is a row somebody cleared by accident; it must not blank the
      // title of every page on the site.
      name: typeof name === "string" && name.trim() !== "" ? name.trim() : PLATFORM_NAME_FALLBACK,
      /*
       * ⚠️ STRIPPED HERE TOO, THOUGH THE PANEL ALREADY STRIPS ON SAVE. The row
       * predates the panel field and can also be written by a seeder or by hand;
       * `wa.me/+974 5551` is a 404, and a dash in it is a link that silently
       * opens nothing.
       */
      supportWhatsapp: typeof whatsapp === "string" ? whatsapp.replace(/[^\d]/g, "") : "",
      legalName: text(body.data?.legal_name),
      postalAddress: text(body.data?.postal_address),
      contactEmail: text(body.data?.contact_email),
    };
  } catch {
    return IDENTITY_FALLBACK;
  }
}

/** The product's name alone — the reader most pages have. */
export async function platformName(): Promise<string> {
  return (await platformIdentity()).name;
}

export const CURRENCY = "QAR";
export const CURRENCY_LABEL = "ر.ق";
