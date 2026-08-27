import { ApiError } from "./api";

/**
 * The Arabic message a user sees when a request fails.
 *
 * Deliberately keyed on the HTTP status, not on the response text. Validation
 * messages (422) are translated at their source in `backend/lang/ar/` and are
 * already Arabic — this function never touches them; forms route 422 through
 * `fieldErrors()` so each message lands under the field it belongs to.
 *
 * Everything else gets a message from this table. The raw `err.message` never
 * reaches the screen (FR-017): it is a developer string, sometimes an exception
 * class name, and always English.
 */

const BY_STATUS: Record<number, string> = {
  401: "انتهت جلستك. سجّل الدخول من جديد.",
  403: "لا تملك صلاحية لهذا الإجراء.",
  404: "العنصر المطلوب غير موجود أو حُذف.",
  409: "تعذّر إتمام العملية — تغيّرت الحالة. حدّث الصفحة وأعد المحاولة.",
  413: "الملف أكبر من الحدّ المسموح.",
  419: "انتهت صلاحية الطلب. أعد المحاولة.",
  429: "محاولات كثيرة في وقت قصير. انتظر قليلاً ثم أعد المحاولة.",
};

/**
 * Refusals specific enough that the generic status message would mislead.
 *
 * Keyed on a `code` the server sends, never on its message text: matching on
 * wording breaks the day someone improves the wording. Only codes listed here
 * are trusted, so a framework default in English can never reach the screen
 * through this door.
 */
const BY_CODE: Record<string, string> = {
  asset_not_ready: "الفيديو قيد التجهيز. حاول بعد قليل.",
  two_factor_required:
    "انتهت مهلة تفعيل التحقق بخطوتين. فعّله من إعدادات الأمان لمتابعة هذه العملية.",
  // Deliberately vague about WHY, because the server is: no seat, outside the
  // window and already closed all answer identically, so a message naming one
  // of them would leak what the API refused to say (FR-015).
  session_not_joinable:
    "لا يمكنك دخول هذه الحصة الآن. تأكّد من حجز مقعدك ومن أن موعدها قد حان.",
  // A classified file held back for an unpaid balance (FR-042). Without an entry
  // here a 402 falls through to "حدث خطأ غير متوقّع" — a dead end on the one
  // refusal the student can clear themselves in a minute.
  //
  // The exact number is deliberately not repeated: the server sends
  // `credits_needed` for a screen that wants to show it, and a count hardcoded
  // in this table would be wrong for every case. `/billing` states it per course
  // and is where the payment happens anyway.
  access_withheld:
    "هذا الملف موقوف حتى سداد رصيد الكورس. حصصك ودروسك العادية لا تتأثّر، ويُفتح فور اعتماد الدفع من صفحة الأرصدة.",
};

const SERVER = "حدث خطأ لدينا. أعد المحاولة بعد قليل، وإن تكرّر فتواصل مع الدعم.";
const OFFLINE = "تعذّر الاتصال. تحقّق من اتصالك بالإنترنت.";

/** Exported so `errorMessage()` can tell "nothing matched" from a real message. */
export const UNKNOWN_MESSAGE = "حدث خطأ غير متوقّع. أعد المحاولة.";
const UNKNOWN = UNKNOWN_MESSAGE;

export function userMessage(err: unknown): string {
  if (err instanceof ApiError) {
    const coded = BY_CODE[errorCode(err.body) ?? ""];
    if (coded) return coded;

    // 422 arrives here only when a caller did not split field errors out. The
    // body message is already Arabic in that case, so passing it through is
    // right — this is not the English-leak path.
    if (err.status === 422) return err.message;

    const known = BY_STATUS[err.status];
    if (known) return known;
    if (err.status >= 500) return SERVER;
  }

  // fetch() rejects with a TypeError when the network is down or the host is
  // unreachable — the single most common failure in local development, and the
  // one whose native message ("Failed to fetch") is least useful to a student.
  if (err instanceof TypeError) return OFFLINE;

  // Kept out of the UI, kept in the console: the developer still needs it.
  if (err instanceof Error) console.error("[api]", err);

  return UNKNOWN;
}

/**
 * The machine code a coded refusal carries beside its sentence.
 *
 * ⚠️ EXPORTED SO A SCREEN CAN SWITCH ON IT WITHOUT READING ARABIC PROSE. The
 * group picker greys one card for `cohort_full` and sends the reader to the
 * transfer form for `already_member`; a client that matched on the message text
 * would break the first time somebody improved the wording.
 */
export function errorCode(body: unknown): string | null {
  if (typeof body === "object" && body !== null && "code" in body) {
    const code = (body as { code: unknown }).code;
    if (typeof code === "string") return code;
  }

  return null;
}
