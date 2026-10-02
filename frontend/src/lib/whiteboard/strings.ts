import { NOUNS, counted } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";

/**
 * Every word the whiteboard shows (spec 039, constraint 8: «all strings via
 * i18n»). One object instead of an i18n library: the app is Arabic-only and has
 * no i18n system (R-16) — this keeps no string scattered in a component, and
 * moving to a library later replaces one import.
 *
 * A counted noun is a FUNCTION through `counted()`, never a template literal
 * («٢ صفحة» shipped elsewhere in this tree and was reported).
 */
export const WB = {
  board: "السبّورة",
  boards: "السبّورات",
  untitled: "سبّورة بلا عنوان",
  loading: "جارٍ تحميل السبّورة…",
  loadingFont: "جارٍ تحميل الخطّ العربي…",
  loadFailed: "تعذّر فتح السبّورة. تحقّق من الاتصال ثم أعد المحاولة.",
  notFound: "هذه السبّورة غير موجودة أو ليست لك.",

  newBoard: "سبّورة جديدة",
  rename: "إعادة التسمية",
  title: "عنوان السبّورة",
  background: "الخلفية",
  backgrounds: { white: "بيضاء", blackboard: "سبّورة سوداء", greenboard: "سبّورة خضراء" },
  save: "حفظ",
  cancel: "إلغاء",

  previousPage: "الصفحة السابقة",
  nextPage: "الصفحة التالية",
  pageOf: (page: number, total: number) => `${arabicNumber(page)} من ${arabicNumber(total)}`,
  pages: (n: number) => counted(n, NOUNS.pages),
  boardsCount: (n: number) => counted(n, NOUNS.boards),

  exportPng: "تصدير الصفحة صورة PNG",
  exportSvg: "تصدير الصفحة SVG",
  exporting: "جارٍ التصدير…",
  exportFailed: "تعذّر التصدير. أعد المحاولة.",

  present: "عرض",
  stopPresenting: "إنهاء العرض",
  presentHint: "تظهر الأدوات حين تقترب بالمؤشّر من أعلى الشاشة.",
  shareHint: "شارك هذا التبويب وحده، لا الشاشة كلها.",

  /** The failure codes of contracts/api.md, one sentence each. */
  errors: {
    too_large: "الملف أكبر من الحدّ المسموح.",
    too_many_pages: "عدد الصفحات أكبر من الحدّ المسموح لهذه السبّورة.",
    unsupported: "صيغة الملف غير مدعومة. المدعوم: PDF وPowerPoint وWord وصور PNG وJPEG.",
    corrupt: "الملف تالف أو لا يُقرأ.",
    timeout: "استغرق تحويل الملف وقتاً أطول من المسموح. جرّب ملفاً أصغر.",
    board_deleted: "حُذفت السبّورة أثناء التحويل.",
    scene_too_large: "محتوى هذه الصفحة أكبر من الحدّ المسموح. قسّمه على صفحتين.",
    board_too_large: "مجموع صفحات السبّورة أكبر من الحدّ المسموح. ابدأ سبّورة جديدة.",
    inline_file: "تعذّر حفظ صورة في الصفحة. أعد إدراجها.",
    unknown_file: "صورة في الصفحة لم تكتمل بعد. انتظر لحظة ثم احفظ.",
    bad_link: "رابط في الصفحة غير مسموح. الروابط المسموحة تبدأ بـ http أو https.",
    bad_element: "عنصر في الصفحة غير مدعوم.",
    lock_lost: "انتقل التحرير إلى مستخدم آخر. صارت السبّورة للقراءة فقط.",
    version_conflict: "حُفظت نسخة أحدث من هذه الصفحة في مكان آخر.",
    pages_changed: "تغيّرت صفحات السبّورة في مكان آخر. أُعيد تحميل الترتيب.",
    operation_pending: "عملية أخرى جارية على هذه السبّورة. انتظر لحظة.",
    import_in_progress: "لديك ملف يُحوَّل الآن. انتظر حتى ينتهي.",
    already_exported: "هذه السبّورة مرفقة بالدرس من قبل.",
    replace_forbidden: "استبدال المرفق يحتاج صلاحية حذف الدروس. اطلب من مدرّس الكورس.",
    asset_not_ready: "الملف لم يكتمل رفعه بعد.",
    asset_mismatch: "هذا الملف لا يخصّ هذا الدرس.",
    last_page: "لا يمكن حذف الصفحة الأخيرة.",
  },
} as const;

export type WbErrorCode = keyof typeof WB.errors;
