import { counted, NOUNS, type CountedForms } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";

/**
 * The sentences /terms and /refunds build around an operator's numbers
 * (2026-09-27, the pre-launch audit).
 *
 * ⚠️ EVERY NUMBER HERE IS A `platform_settings` ROW, read live from
 * `GET /api/v1/platform` — the pages used to spell «٢٤ حصة», «٦٠ يوماً» and
 * «٤٨ ساعة» as literals, so the day an operator moved one the page promised a
 * number the Action no longer enforced. Each function takes the value or `null`
 * and answers `null` when it cannot say, and the page then leaves the sentence
 * out: a term of service stated from a guess is a promise the product may break.
 *
 * ⚠️ AND EVERY COUNT GOES THROUGH `counted()`, with the dual overridden wherever
 * the noun follows a preposition or a verb («بعد يومين», «حتى حصتين») — a
 * template literal reads wrongly the day an operator sets 1, 2 or 11.
 */

const DAYS_GEN: CountedForms = { ...NOUNS.days, two: "يومين" };
const HOURS_GEN: CountedForms = { ...NOUNS.hours, two: "ساعتين" };
const MINUTES_GEN: CountedForms = { ...NOUNS.minutes, two: "دقيقتين" };
const SESSIONS_GEN: CountedForms = { ...NOUNS.sessions, two: "حصتين" };
const MONTHS: CountedForms = {
  one: "شهر واحد",
  two: "شهرين",
  few: "أشهر",
  many: "شهراً",
  other: "شهر",
};
const PAYMENTS: CountedForms = {
  one: "دفعة",
  two: "دفعتين",
  few: "دفعات",
  many: "دفعة",
  other: "دفعة",
};
const MESSAGES: CountedForms = {
  one: "رسالة واحدة",
  two: "رسالتين",
  few: "رسائل",
  many: "رسالة",
  other: "رسالة",
};

const known = (value: number | null | undefined): value is number =>
  typeof value === "number" && Number.isInteger(value) && value >= 0;

/**
 * A duration held in minutes, said in the largest unit that divides it: 1440 is
 * «٢٤ ساعة», 90 stays «٩٠ دقيقة». Genitive — it follows «عن» / «خلال».
 */
export function minutesAsWords(minutes: number): string {
  return minutes % 60 === 0
    ? counted(minutes / 60, HOURS_GEN)
    : counted(minutes, MINUTES_GEN);
}

/** «… بعد مهلة ١٤ يوماً …» — the mandatory second factor for staff. */
export function twoFactorSentence(days: number | null): string | null {
  if (!known(days)) return null;

  const when = days === 0 ? "من أول دخول" : `بعد مهلة ${counted(days, DAYS_GEN)}`;

  return `التحقّق بخطوتين إلزاميّ للمدرّسين وفريق العمل ${when}، وبدونه تُقفل عنهم العمليات الحسّاسة كالموافقة على المدفوعات والتسعير.`;
}

/** «نسعى إلى المراجعة خلال ٢٤ ساعة …» — a target, and the page says so. */
export function receiptReviewSentence(hours: number | null): string | null {
  if (!known(hours) || hours === 0) return null;

  return `بعد التحويل ترفع صورة الإيصال (JPG أو PNG أو PDF، حتى ١٠ ميجابايت)، ويراجعها فريق المالية. نسعى إلى المراجعة خلال ${counted(hours, HOURS_GEN)}، وهذا هدفٌ لا موعدٌ ملزِم.`;
}

/** The ceiling on unconsumed credits in one course. */
export function creditCeilingSentence(credits: number | null): string | null {
  if (!known(credits) || credits === 0) return null;

  return `لا يمكن أن يزيد ما لم تستهلكه في الكورس الواحد على ${counted(credits, SESSIONS_GEN)}، بما فيها الطلبات التي لم تُراجَع بعد.`;
}

/** A course with no delivered session for N days stops selling. */
export function stopSellingSentence(days: number | null): string | null {
  if (!known(days) || days === 0) return null;

  return `يتوقّف بيع الحصص في كورسٍ لم تُقدَّم فيه أي حصة منذ ${counted(days, DAYS_GEN)}، أو في كورسٍ غير منشور.`;
}

/** «… نرسل لك تنبيهاً بعد ١٢ شهراً …» — the dormancy notice, which takes nothing. */
export function dormancyPeriod(months: number | null): string | null {
  return known(months) && months > 0 ? counted(months, MONTHS) : null;
}

/** The free-cancellation window. Zero means there is none, so nothing is promised. */
export function cancellationWindow(minutes: number | null): string | null {
  return known(minutes) && minutes > 0 ? minutesAsWords(minutes) : null;
}

/**
 * The attendance bar a charge is judged against: «نصف مدّة الحصة» at 50, and
 * «٧٥٪ من مدّة الحصة» anywhere else.
 */
export function attendanceBar(percent: number | null): string | null {
  if (!known(percent) || percent === 0) return null;

  return percent === 50 ? "نصف مدّة الحصة" : `${arabicNumber(percent)}٪ من مدّة الحصة`;
}

/** How long attendance may be corrected after a session. */
export function attendanceCorrectionSentence(hours: number | null): string | null {
  if (!known(hours) || hours === 0) return null;

  return `يمكن تصحيح الحضور خلال ${counted(hours, HOURS_GEN)} من الحصة. وتحويل غيابك إلى «معذور» بعد الخصم يعيد الحصة إلى رصيدك.`;
}

export type DeferredLadder = {
  initial: number | null;
  increaseAfterOnTime: number | null;
  increaseBy: number | null;
  max: number | null;
  resetAfterLateDays: number | null;
};

/**
 * The deferred-payment ceiling: where it starts, how it grows, and when it falls
 * back to zero. Any part the API did not send drops the clause it belongs to;
 * with no starting point there is no sentence at all.
 */
export function deferredLadderSentence(ladder: DeferredLadder): string | null {
  const { initial, increaseAfterOnTime, increaseBy, max, resetAfterLateDays } = ladder;

  if (!known(initial)) return null;

  const opening = counted(initial, { ...SESSIONS_GEN, one: "حصةٍ واحدة" });
  // «بـ٢» before a numeral, «بحصتين» before a word — the kashida joins a digit only.
  const start =
    initial === 0
      ? "يبدأ الحدّ المسموح من صفر"
      : `يبدأ الحدّ المسموح ${/^[٠-٩]/.test(opening) ? "بـ" : "ب"}${opening}`;

  const grows =
    known(increaseAfterOnTime) && increaseAfterOnTime > 0
    && known(increaseBy) && increaseBy > 0
    && known(max) && max > initial
      ? `، ويزيد ${counted(increaseBy, { ...SESSIONS_GEN, one: "حصةً" })} بعد كل ${counted(increaseAfterOnTime, PAYMENTS)} في موعدها حتى ${counted(max, SESSIONS_GEN)}`
      : "";

  const reset =
    known(resetAfterLateDays) && resetAfterLateDays > 0
      ? ` وإن بقي رصيدك بالسالب أكثر من ${counted(resetAfterLateDays, DAYS_GEN)} يعود الحدّ إلى صفر وتصير الحصص بالدفع المسبق.`
      : "";

  return `${start}${grows}.${reset}`;
}

/** No automatic renewal, and the reminder before expiry when there is one. */
export function renewalSentence(days: number | null): string {
  const reminder =
    known(days) && days > 0
      ? ` نرسل لك تنبيهاً قبل ${counted(days, DAYS_GEN)} من انتهائه، وعند انتهائه يُغلق ما كان يفتحه.`
      : " وعند انتهائه يُغلق ما كان يفتحه.";

  return `لا يتجدّد الاشتراك تلقائياً.${reminder}`;
}

/** Report anything; the chat's per-minute ceiling when it is known. */
export function chatLimitSentence(perMinute: number | null): string {
  const limit =
    known(perMinute) && perMinute > 0
      ? ` والمحادثة محدودة حالياً بما لا يزيد على ${counted(perMinute, MESSAGES)} في الدقيقة.`
      : "";

  return `يمكنك الإبلاغ عن أي رسالة أو تقييم.${limit}`;
}

/** Who may rate a teacher, and how often. */
export function reviewSentence(minSessions: number | null, periodDays: number | null): string | null {
  if (!known(minSessions) || !known(periodDays) || periodDays === 0) return null;

  const after =
    minSessions === 0
      ? "يمكنك تقييم مدرّسك"
      : `يمكنك تقييم مدرّسك بعد أن تحضر عنده ${counted(minSessions, SESSIONS_GEN)}`;

  return `${after}، مرةً كل ${counted(periodDays, { ...DAYS_GEN, one: "يوم" })}. وللمنصّة أن تُخفي التقييم المخالف.`;
}

/** A departing teacher's notice period, when it is known. */
export function offboardingSentence(days: number | null): string {
  const notice =
    known(days) && days > 0
      ? `يُمهَل المدرّس ${counted(days, { ...DAYS_GEN, one: "يوماً واحداً" })} بعد طلب المغادرة، ولا تكتمل مغادرته قبل تسوية مستحقاته.`
      : "لا تكتمل مغادرة المدرّس قبل تسوية مستحقاته.";

  return `${notice} وبعدها تبقى الكورسات التي اشتريتها منه متاحةً لك، وتصير محادثاته للقراءة فقط.`;
}

/** The store's self-service refund window; zero means there is no such button. */
export function storeRefundSentence(hours: number | null): string | null {
  if (!known(hours) || hours === 0) return null;

  return `يمكنك طلب الاسترداد من صفحة مشترياتك خلال ${counted(hours, HOURS_GEN)} من الشراء، بشرط ألّا تكون قد فتحت الملف الرقمي، وألّا تكون النسخة المطبوعة قد شُحنت. ولكل مشترى طلب استرداد واحد.`;
}
