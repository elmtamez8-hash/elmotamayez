/**
 * The product is «المتميز» — PRODUCT.md records it as a binding brand
 * commitment, and the wordmark in the header spells it.
 *
 * ⚠️ The fallback below is a placeholder and shipped as the real name for
 * months, because NEXT_PUBLIC_PLATFORM_NAME was set nowhere and documented
 * nowhere — not even in .env.example. Every title tag, header and footer said
 * «منصّتي». A decided name that the deployment never carries is not a naming
 * problem; it is a page whose logo and heading disagree in the first second.
 *
 * The key is in .env.example now. The fallback stays because a missing
 * environment variable should degrade, not crash — but it is the failure
 * state, not the default.
 */
export const PLATFORM_NAME = process.env.NEXT_PUBLIC_PLATFORM_NAME ?? "منصّتي";

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
