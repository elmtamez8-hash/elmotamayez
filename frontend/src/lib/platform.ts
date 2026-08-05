/**
 * The platform name is still a placeholder. It lives here, in one place, so
 * naming the product later is a single edit rather than a grep across every
 * heading, title tag and footer.
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
