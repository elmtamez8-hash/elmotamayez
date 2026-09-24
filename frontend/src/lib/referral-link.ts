/**
 * The shareable half of an invitation (spec 011 · FR-018).
 *
 * No imports, on purpose: the signup pages read `?ref=` in a SERVER component,
 * and pulling `lib/api` in there for three string functions would drag the
 * client's token plumbing into a server bundle for nothing.
 */

/** The same ceiling `RegisterStudentRequest` puts on `referral_code`. */
export const REFERRAL_CODE_MAX = 12;

/**
 * A `?ref=` value made safe to drop into a form field.
 *
 * ⚠️ IT CAME OFF THE ADDRESS BAR, so anything outside the code alphabet is
 * dropped rather than trusted, and it is upper-cased because that is how codes
 * are issued (`ReferralCode::generate()`) and compared. This decides nothing —
 * the server still looks the code up and answers 422 if it is unknown — it only
 * keeps a mangled link from prefilling the field with punctuation.
 */
export function sanitiseReferralCode(raw: unknown): string {
  const value = Array.isArray(raw) ? raw[0] : raw;

  if (typeof value !== "string") return "";

  return value
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "")
    .slice(0, REFERRAL_CODE_MAX);
}

/**
 * The link a friend opens: the student signup form, with the code prefilled.
 *
 * Built from the ORIGIN THE PAGE IS OPEN ON rather than from `SITE_URL`: that
 * one is server-only by design (`lib/site.ts`), and the inviter is by definition
 * looking at the site the friend should land on.
 */
export function referralSignupLink(origin: string, code: string): string {
  return `${origin.replace(/\/+$/, "")}/signup/student?ref=${encodeURIComponent(code)}`;
}

/**
 * `wa.me` with no number opens WhatsApp's own contact picker with the text
 * already written — the inviter chooses who, not us.
 */
export function whatsAppShareHref(link: string): string {
  const message = `انضمّ إليّ على المنصّة وتعلّم مع مدرّسين تختارهم بنفسك. أنشئ حسابك من هذا الرابط:\n${link}`;

  return `https://wa.me/?text=${encodeURIComponent(message)}`;
}
