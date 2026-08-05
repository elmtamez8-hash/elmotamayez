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

const SERVER = "حدث خطأ لدينا. أعد المحاولة بعد قليل، وإن تكرّر فتواصل مع الدعم.";
const OFFLINE = "تعذّر الاتصال. تحقّق من اتصالك بالإنترنت.";

/** Exported so `errorMessage()` can tell "nothing matched" from a real message. */
export const UNKNOWN_MESSAGE = "حدث خطأ غير متوقّع. أعد المحاولة.";
const UNKNOWN = UNKNOWN_MESSAGE;

export function userMessage(err: unknown): string {
  if (err instanceof ApiError) {
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
