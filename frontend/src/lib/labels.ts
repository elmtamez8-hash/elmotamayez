/**
 * Arabic labels for the status strings the API returns.
 *
 * The API sends machine values (`in_progress`, `approved`) and always will —
 * they are contract, not copy. Translating them at the point of display, in one
 * table, is what keeps FR-003 from being re-litigated on every screen.
 *
 * Unknown values fall through to the raw string rather than an empty cell: a new
 * backend status should look unfinished, not invisible.
 */

const STATUS_LABELS: Record<string, string> = {
  // Enrollment · order · attempt
  active: "جارٍ",
  completed: "مكتمل",
  pending: "بانتظار الدفع",
  under_review: "قيد المراجعة",
  approved: "معتمَد",
  rejected: "مرفوض",
  cancelled: "ملغى",
  expired: "منتهٍ",
  suspended: "موقوف",
  in_progress: "قيد التنفيذ",
  submitted: "مُسلَّم",
  graded: "مُصحَّح",
  passed: "ناجح",
  failed: "راسب",

  // Course · exam
  draft: "مسودّة",
  published: "منشور",
  archived: "مؤرشف",

  // Visibility
  public: "عام",
  private: "خاص",
  hidden: "مخفي",

  // Settlement (014). `accrued` and `settled` are two different answers to "am I
  // getting paid for this": earned, and already paid out.
  pending_package: "بانتظار اكتمال الحزمة",
  accrued: "مستحقّة",
  disputed: "متنازع عليها",
  settled: "مسوّاة",
  reversed: "عكسية",
  open: "مفتوحة",
  closed: "مغلقة",
  paid: "مصروفة",
};

export function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status;
}

/**
 * Colour band for a status pill. Colour is never the only carrier of meaning —
 * the label sits inside the pill — so this is emphasis, not information.
 */
export type StatusTone = "neutral" | "success" | "warning" | "danger" | "info";

const STATUS_TONES: Record<string, StatusTone> = {
  active: "info",
  in_progress: "info",
  published: "success",
  completed: "success",
  approved: "success",
  passed: "success",
  pending: "warning",
  under_review: "warning",
  submitted: "warning",
  draft: "neutral",
  archived: "neutral",
  cancelled: "neutral",
  expired: "neutral",
  rejected: "danger",
  failed: "danger",
  suspended: "danger",

  // Settlement. A reversal is not a failure — it is a correction with an author
  // and a reason — so it reads neutral rather than red.
  accrued: "success",
  settled: "success",
  paid: "success",
  pending_package: "warning",
  disputed: "danger",
  reversed: "neutral",
  open: "info",
  closed: "neutral",
};

export function statusTone(status: string): StatusTone {
  return STATUS_TONES[status] ?? "neutral";
}

/** Tailwind classes per tone, in tokens only — no literal colours (FR-008). */
export const TONE_CLASSES: Record<StatusTone, string> = {
  neutral: "bg-line text-ink",
  success: "bg-secondary/15 text-secondary-ink",
  warning: "bg-accent/20 text-accent-foreground",
  danger: "bg-danger/15 text-danger-ink",
  info: "bg-primary-soft text-primary-ink",
};

/**
 * A fallback for a payload that carries no `type_label`, and nothing more.
 *
 * The API sends `type_label` on every lesson it serialises, straight from
 * `LessonType::label()` — prefer that. This map existed before it did and had
 * already drifted: it said "مقال" and "ملف PDF" where the enum says "مقالة" and
 * "مستند PDF", and it covered four of the ten types. So one teacher saw two names
 * for one type on two panels of the same screen.
 *
 * Aligned to the enum and completed, but it stays a fallback: a second list of
 * these strings in the client is a second thing to keep in step, and the one that
 * is not shipped with the value it labels is the one that drifts.
 */
const LESSON_TYPE_LABELS: Record<string, string> = {
  video: "فيديو",
  audio: "صوت",
  pdf: "مستند PDF",
  file: "ملف",
  article: "مقالة",
  note: "تنويه",
  link: "رابط خارجي",
  exam: "اختبار",
  assignment: "واجب",
  live_session: "حصة مباشرة",
};

export function lessonTypeLabel(type: string): string {
  return LESSON_TYPE_LABELS[type] ?? type;
}

const DIFFICULTY_LABELS: Record<string, string> = {
  easy: "سهل",
  medium: "متوسّط",
  hard: "صعب",
};

export function difficultyLabel(level: string): string {
  return DIFFICULTY_LABELS[level] ?? level;
}

/**
 * Why a session ended, as the person who was signed out reads it.
 *
 * Kept here rather than taken from the API on purpose: the endpoint that answers
 * this is unauthenticated, so it returns a code and two fields — a sentence
 * there would be a sentence anyone could fetch. `null` is a real answer, not a
 * failure: it means the session is still alive and the sign-out was ordinary.
 */
const SESSION_ENDED_LABELS: Record<string, string> = {
  device_limit: "سُجّل الدخول إلى حسابك من جهاز آخر، فأُنهيت هذه الجلسة.",
  password_change: "تغيّرت كلمة مرور حسابك، فأُنهيت الجلسات الأخرى.",
  two_factor_change: "تغيّرت إعدادات التحقّق بخطوتين، فأُنهيت الجلسات الأخرى.",
  manual: "أُنهيت هذه الجلسة من قائمة أجهزتك.",
  expired: "انتهت صلاحية الجلسة.",
  logout: "سجّلت الخروج.",
};

export function sessionEndedLabel(reason: string | null): string | null {
  if (reason === null) return null;

  return SESSION_ENDED_LABELS[reason] ?? "انتهت جلستك. سجّل الدخول من جديد.";
}

const ROLE_LABELS: Record<string, string> = {
  owner: "مالك",
  admin: "مدير",
  teacher: "مدرّس",
  assistant: "مساعد",
  student: "طالب",
  parent: "وليّ أمر",
  member: "عضو",
};

export function roleLabel(role: string): string {
  return ROLE_LABELS[role] ?? role;
}

/** Dates are shown in Arabic with Western digits — Arabic-Indic digits inside a
 *  Latin-heavy UI (invoice numbers, IDs) read as a different number system. */
export function formatDate(value: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleDateString("ar", {
    year: "numeric",
    month: "long",
    day: "numeric",
    numberingSystem: "latn",
  });
}

export function formatMoney(amount: number, currency: string): string {
  return new Intl.NumberFormat("ar", {
    style: "currency",
    currency,
    numberingSystem: "latn",
  }).format(amount);
}

/**
 * The same, for an amount that arrived in MINOR units.
 *
 * Settlement sends integers and a currency code rather than formatted text, so
 * that the client can do arithmetic without parsing a string back. The divisor
 * is the whole reason this is a function: every supported currency has two
 * decimals today, and that assumption belongs in one place — the backend states
 * it once in `Money.php`, and a `/ 100` repeated across components is where it
 * quietly stops being true for the first currency that has three.
 */
export function formatMinorMoney(minor: number, currency: string): string {
  const MINOR_UNITS = 100;

  return formatMoney(minor / MINOR_UNITS, currency);
}
