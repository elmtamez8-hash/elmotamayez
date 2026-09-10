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

  // Adaptive practice (012). `mastered` and `ended` are two OUTCOMES of one
  // session, not one with a flag — only the first awards anything, and a label
  // that blurred them would put «أُتقِنت» on a session the student gave up on.
  running: "جارية",
  mastered: "أُتقِنت",
  ended: "انتهت",

  /*
    Live sessions (005 · 017). `scheduled`, `live` and `interrupted` were the
    three the table never had — so a teacher's own group page badged every
    upcoming lesson «scheduled», in English, on an Arabic-only product. The
    fall-through is deliberate («a new backend status should look unfinished,
    not invisible») and it is a diagnostic, not a design: a value that reaches a
    screen belongs here.

    ⚠️ `interrupted` IS NOT `cancelled`. It is the sweep's verdict on a session
    the teacher never opened — «لم تنعقد» — while a cancellation is a decision
    somebody took and announced. Reading them as one word hides which of the two
    happened from the only person who can fix it.
  */
  scheduled: "مجدولة",
  live: "جارية الآن",
  interrupted: "لم تنعقد",

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

  // Adaptive practice (012). `ended` is neutral and NOT a failure: stopping is
  // an ordinary way to finish a revision session, and painting it red would
  // scold a student for closing a page.
  running: "info",
  mastered: "success",
  ended: "neutral",

  // Live sessions. `live` is emphasis and not alarm; `interrupted` is the
  // sweep's verdict on a lesson nobody opened, which is a fault worth seeing.
  scheduled: "info",
  live: "success",
  interrupted: "danger",

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
  /*
    ⚠️ `text-ink`, NOT `text-accent-foreground` — WHICH IS WHITE, AND EARNED THAT
    WAY AGAINST THE SOLID BRASS FILL.

    On a 20% tint over a card it is roughly 1.3:1. It carries the confused count
    in a live lesson («🤔 ٤ لم يفهموا»), the seat warning at two seats left, and
    the grading count in the shell — three numbers whose whole job is to be read
    at a glance. The token is right where `bg-accent` is solid; this is not that.
  */
  warning: "bg-accent/20 text-ink",
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
  embed: "فيديو مُضمَّن",
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

/**
 * Arabic for a role, from the SERVER.
 *
 * ⚠️ THE MAP THAT USED TO LIVE HERE HAD DRIFTED, AND THAT IS THE WHOLE STORY. It
 * was keyed `owner`, `admin`, `assistant` — words that are not role names in this
 * product. The real ones come from `Tenancy\Support\Roles`: `tenant-owner`,
 * `assistant-teacher`, `super-admin`, `finance-admin`, `compliance-officer`. So
 * FIVE of the seven missed the map entirely and rendered through its `?? role`
 * fallback as English slugs on an Arabic-only screen — `tenant-owner` sat in a
 * badge on `/members` and nobody could have translated it here without first
 * noticing the keys were fiction.
 *
 * The wording now travels with the payload (`role_label`, `pivot_role_label`),
 * beside the names themselves, exactly as notification type labels do — a second
 * copy in the browser is stale the day the first one changes, and this one was
 * stale before anybody read it.
 *
 * ⚠️ AND THE FALLBACK IS THE NAME ITSELF, ON PURPOSE. Roles are editable from
 * `/admin`, so an owner can create one and name it — in Arabic, for their own
 * workspace. Showing that name unchanged is right; a blank would be an empty
 * badge and a guess would be worse.
 */
/**
 * Why a certificate was issued.
 *
 * ⚠️ ONE MAP, read by the public verification page and by the student's own
 * list. It lived inside the verify page as a local constant until a second
 * screen needed it — and Arabic status labels belong here by convention, exactly
 * so the two cannot drift into «إتمام الكورس» on one screen and something else
 * on the next. An unknown value falls through to itself rather than to an empty
 * cell: a reason nobody has translated yet is still a fact about the row.
 */
export function certificateReasonLabel(reason: string): string {
  return (
    {
      course_completed: "إتمام الكورس",
      exam_passed: "اجتياز الاختبار",
      manual: "إصدار يدوي",
    }[reason] ?? reason
  );
}

export function roleLabel(role: string, label?: string | null): string {
  return label ?? role;
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

/**
 * Date AND time, for a record whose whole point is when it happened.
 *
 * Separate from formatDate rather than a flag on it: "آخر تشغيل: ١٣ أغسطس" is
 * useless for a sweep that runs every hour — it answers a question nobody asked
 * while looking like it answered theirs.
 */
/**
 * «اليوم» · «أمس» · a written date — for grouping a list that looks BACKWARDS.
 *
 * ⚠️ NOT `formatSessionDay()`. That one belongs to the schedule and knows
 * «غداً», which is the right word in front of a timetable and a wrong one over a
 * feed of things that have already happened. Two readings of «yesterday» and
 * «tomorrow» in one helper would be one function answering two questions.
 */
export function relativeDayLabel(value: string | null): string {
  if (value === null) return "";

  const at = new Date(value);

  if (Number.isNaN(at.getTime())) return "";

  const key = (d: Date) => d.toLocaleDateString("en-CA");
  const today = new Date();
  const yesterday = new Date(today.getTime() - 86_400_000);

  if (key(at) === key(today)) return "اليوم";
  if (key(at) === key(yesterday)) return "أمس";

  return at.toLocaleDateString("ar", {
    day: "numeric",
    month: "long",
    year: at.getFullYear() === today.getFullYear() ? undefined : "numeric",
    numberingSystem: "latn",
  });
}

export function formatDateTime(value: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleString("ar", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    numberingSystem: "latn",
  });
}

/**
 * The clock alone, for a row whose date is already written above it.
 *
 * A chat bubble sits under a day separator, so repeating «٢٣ أغسطس ٢٠٢٦» on every
 * message is the date said twice — and on a phone it is wider than most of the
 * sentences it is stamping.
 */
export function formatTime(value: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleTimeString("ar", {
    hour: "2-digit",
    minute: "2-digit",
    numberingSystem: "latn",
  });
}

/**
 * The value of an `<input type="datetime-local">` as an absolute instant.
 *
 * ⚠️ THAT INPUT CARRIES NO TIMEZONE, AND SENDING IT RAW MOVED A LESSON THREE
 * HOURS. Its value is a naive wall clock — `2026-09-03T20:31` — and the API runs
 * on `APP_TIMEZONE=UTC`, so a teacher in Qatar (+03) who typed «now» created a
 * session starting at 23:31 their time. Reported from production on 2026-09-03:
 * «تعذّر الدخول» on a lesson the teacher had just scheduled for that minute, with
 * `joinWindowCovers()` correctly answering false about a room 179 minutes away.
 *
 * ⚠️ AND `after:now` CANNOT SEE IT — 20:31 UTC is a perfectly valid future
 * instant, so the one rule that might have caught it passes. Nothing downstream
 * can recover the offset either: it was never sent. The browser is the only
 * party that knows which zone the operator meant, so the conversion belongs here
 * and nowhere else.
 *
 * `new Date(naive)` reads the string in the BROWSER's zone — which is exactly
 * what the operator meant by it — and `toISOString()` makes that absolute.
 *
 * ⛔ Not for `<input type="date">`. A freeze period, an exam window and a
 * collection report carry DATES, and their columns are dates: pushing one
 * through here turns it into midnight-in-some-zone and moves it by a day at the
 * boundary — the same off-by-one this repository has already paid for twice.
 */
export function localDateTimeToIso(value: string): string {
  if (!value) return value;

  const at = new Date(value);

  // An unparseable value is returned untouched rather than replaced: the server's
  // own `date` rule then refuses it with a field error the operator can read,
  // which is better than inventing an instant nobody chose.
  return Number.isNaN(at.getTime()) ? value : at.toISOString();
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

/**
 * A form field ↔ the minor-unit integer the API takes.
 *
 * ⚠️ THE CONVERSION LIVES HERE, NOT IN THE FORM. A teacher types riyals — 49.99
 * — and the API takes 4999, so every price form does this twice, once each way.
 * Written inline, the fifth form is where someone sends 49.99 to a column that
 * reads it as 49 fils: a hundredfold undercharge that no type checker sees,
 * because both numbers are numbers.
 *
 * Math.round, never a truncation: 49.99 * 100 is 4998.999999999999 in IEEE-754,
 * and `Math.trunc` would price the course a fils short — forever, silently.
 */
export function toMinorMoney(input: string): number {
  const MINOR_UNITS = 100;
  const value = parseFloat(input);

  return Number.isFinite(value) ? Math.round(value * MINOR_UNITS) : 0;
}

/** The inverse, for filling a form from what the API sent. */
export function fromMinorMoney(minor: number): string {
  const MINOR_UNITS = 100;

  return (minor / MINOR_UNITS).toFixed(2);
}

/**
 * The five forms an Arabic counted noun takes, so a sentence can agree.
 *
 * ⚠️ «٢ مدرّس متاح» IS THE DEFECT, AND IT WAS SPELLED TWENTY-FIVE TIMES. Arabic
 * agrees the noun AND its adjective with the number in five bands, and a
 * template literal knows about none of them: the marketplace banner said «٢
 * مدرّس متاح» for two teachers and «١١ مدرّس متاح» for eleven — the second
 * accidentally right, the first wrong in both words. Reported from the live
 * marketplace on 2026-09-11.
 *
 * ⚠️ AND FOUR SCREENS HAD ALREADY WRITTEN THEIR OWN HALF OF THIS RULE. The
 * orders table, the lesson preview, the course page and the cohort card each
 * carried a private `if (count <= 10)` ladder — four spellings of one rule,
 * which is the drift this file exists to prevent. They read this now.
 *
 * The BANDS come from `Intl.PluralRules("ar")` rather than from a hand-written
 * `n % 100` chain: CLDR is where the rule is maintained, and the two disagree
 * exactly where a hand-written one is most often wrong — 103 is `few` while 111
 * is `many`, because the band is decided by the last two digits and not by the
 * size of the number.
 *
 * ⚠️ THE NUMERAL IS THE HELPER'S DECISION, NOT THE CALLER'S. One and two are
 * written WITHOUT a numeral («مدرّس متاح» · «مدرّسان متاحان») because Arabic
 * carries the count in the word itself and «٢ مدرّسان» is a stutter; everything
 * else is prefixed. Left to the caller, the next one passes a phrase with a
 * digit already in it and the rule is back to being spelled twice.
 *
 * `other` (100, 101, 200 …) falls back to the SINGULAR phrase, which is what it
 * takes — «١٠٠ مدرّس متاح» — and `zero` to «لا » plus the plural, which is a
 * sentence rather than «٠ مدرّس».
 */
export type CountedForms = {
  /** مدرّس متاح */
  one: string;
  /** مدرّسان متاحان */
  two: string;
  /** ٣ مدرّسين متاحين */
  few: string;
  /** ١١ مدرّساً متاحاً */
  many: string;
  /**
   * ١٠٠ مدرّس متاح — the SINGULAR noun, and required rather than defaulted.
   *
   * ⚠️ IT DEFAULTED TO `one` FOR ONE REVIEW AND THAT WAS A REGRESSION. Three
   * callers write «حصة واحدة» · «درس واحد» · «طالب واحد» — the word «واحد»
   * belongs to the standalone singular and cannot follow a numeral, so the
   * default printed «١٠٠ طالب واحد» on the course fact strip, which the
   * template literal it replaced got right. `enrolled_count` is the one count
   * here that realistically passes a hundred. Required, `tsc` names every site;
   * optional, the next caller ships the same sentence.
   */
  other: string;
  /** لا مدرّسين متاحين — defaults to «لا » + `few`, which is a bare plural everywhere. */
  zero?: string;
};

const ARABIC_BANDS = new Intl.PluralRules("ar");

export function counted(count: number, forms: CountedForms): string {
  const band = ARABIC_BANDS.select(count);

  if (band === "zero") return forms.zero ?? `لا ${forms.few}`;
  if (band === "one") return forms.one;
  if (band === "two") return forms.two;

  const noun = band === "few" ? forms.few : band === "many" ? forms.many : forms.other;

  return `${count.toLocaleString("ar-QA")} ${noun}`;
}
