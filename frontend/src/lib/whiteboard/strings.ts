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
  screenUp: "↑ أعلى",
  screenDown: "↓ أسفل",
  screenNew: "↓ مكان فارغ",
  screenOf: (screen: number, total: number) => `شاشة ${arabicNumber(screen)} من ${arabicNumber(total)}`,
  pageOf: (page: number, total: number) => `${arabicNumber(page)} من ${arabicNumber(total)}`,
  pages: (n: number) => counted(n, NOUNS.pages),
  boardsCount: (n: number) => counted(n, NOUNS.boards),

  export: "تصدير الصفحة:",
  exportPng: "PNG",
  exportSvg: "SVG",
  exporting: "جارٍ التصدير…",
  exportFailed: "تعذّر التصدير. أعد المحاولة.",

  present: "عرض",
  stopPresenting: "إنهاء العرض",
  presentHint: "تظهر الأدوات حين تقترب بالمؤشّر من أعلى الشاشة.",
  shareHint: "شارك هذا التبويب وحده، لا الشاشة كلها.",

  /** The toolbar's menus. */
  menus: { tools: "أدوات", present: "عرض", encourage: "تشجيع", layout: "الواجهة" },
  panels: { board: "لوحة السبّورة", tools: "أدوات الرسم", pages: "الصفحات", zoom: "التكبير والتراجع", library: "المكتبة والقائمة" },
  panelModes: { shown: "ظاهرة", folded: "مطويّة", auto: "تظهر لما تقرّب" },
  panelUnfold: (name: string) => `▾ ${name}`,

  /** Teaching tools (US9). */
  tools: {
    template: "خلفية الصفحة:",
    templates: {
      none: "بلا",
      lined: "مسطّرة",
      grid: "مربّعات",
      dotted: "منقّطة",
      isometric: "متساوية القياس",
      graph: "رسم بياني",
      "arabic-lines": "كرّاسة عربية",
    },
    pen: "القلم:",
    pens: { marker: "ماركر", brush: "فرشاة عريضة", highlighter: "قلم تظليل" },
    passing: "أدوات مؤقّتة:",
    names: { magnifier: "عدسة", curtain: "ستارة", wheel: "عجلة الاختيار" },
    curtain: "حافة الستارة: اسحبها لكشف ما تحتها",
    reveal: "اكشف قليلاً",
    closeCurtain: "إزالة الستارة",
    wheelNames: "الأسماء، اسم في كل سطر (أو رقم واحد مثل ٣٠):",
    wheelPlaceholder: "أحمد\nمريم\nيوسف",
    wheelNoRepeat: "بدون تكرار حتى تنتهي الجولة",
    wheelLast: (name: string) => `الأخير: ${name}`,
    wheelOrder: "ترتيب الاختيار",
    wheelNewRound: "جولة جديدة",
    wheelNoPicks: "لم يُختر أحد بعد.",
    spin: "أدر العجلة",
    closeWheel: "إغلاق",
    geometry: "هندسة:",
    instruments: { ruler: "مسطرة", protractor: "منقلة", compass: "فرجار", "set-square": "مثلث قائم" },
    turn: "اسحب لتدوير الأداة",
    open: "اسحب لفتح الفرجار",
    cm: "سم",
    closeInstrument: "إزالة الأداة",
  },

  /** The presenter's tools (US8). */
  presenter: {
    title: "عرض:",
    laser: "ليزر",
    spotlight: "كشّاف",
    spotlightOff: "إطفاء الكشّاف",
    spotlightOn: "الكشّاف يعمل: [ و ] لتصغير الدائرة وتكبيرها، وEsc لإطفائه",
    timer: "مؤقّت:",
    minutes: (n: number) => counted(n, NOUNS.minutes),
    pause: "إيقاف مؤقّت",
    resume: "متابعة",
    closeTimer: "إغلاق",
    timeUp: "انتهى الوقت",
  },

  /** Encouragement and pointer effects (US10, US12). */
  effects: {
    title: "تشجيع:",
    groups: { praise: "تشجيع:", order: "تنبيه:", fun: "هزار:" },
    names: {
      applause: "تصفيق",
      balloons: "بالونات",
      party: "احتفال",
      stars: "نجوم",
      drumroll: "طبلة",
      attention: "انتباه",
      hearts: "قلوب",
      thumbs: "إعجاب",
      bubbles: "فقاعات",
      airplane: "طيارة بهدية",
      egg: "بيضة",
      tomato: "طماطم",
      brick: "طوبة",
      whistle: "صفّارة",
      stick: "عصا المعلم",
      warning: "تحذير",
      wrong: "خطأ",
      yellowCard: "كارت أصفر",
      redCard: "كارت أحمر",
    },
    icons: {
      applause: "👏",
      balloons: "🎈",
      party: "🎉",
      stars: "⭐",
      drumroll: "🥁",
      attention: "🔨",
      hearts: "❤️",
      thumbs: "👍",
      bubbles: "🔵", // not 🫧: Unicode 14, a blank box on Windows 10
      airplane: "✈️",
      egg: "🥚",
      tomato: "🍅",
      brick: "🧱",
      whistle: "📯",
      stick: "👨‍🏫", // not 🪄: Unicode 13
      warning: "⚠️",
      wrong: "❌",
      yellowCard: "🟨",
      redCard: "🟥",
    },
    warning: "تنبيه!",
    attention: "انتباه!",
    popBalloon: "فرقع البالونة",
    stickers: "ملصق:",
    sound: "صوت المؤثرات",
    soundHint: "يسمعه الطلاب فقط إذا شاركت صوت التبويب مع الشاشة.",
    trail: "أثر المؤشّر:",
    trails: { off: "بلا أثر", neon: "نيون", sparks: "شرارات", rainbow: "ألوان" },
  },

  /** The pages strip (US3). */
  pagesTitle: "صفحات السبّورة",
  showPages: "الصفحات",
  pageNumber: (n: number) => `صفحة ${arabicNumber(n)}`,
  addPage: "صفحة جديدة",
  duplicatePage: "نسخ",
  deletePage: "حذف",
  confirmDeletePage: "تأكيد الحذف",
  moveUp: "انقل الصفحة لأعلى",
  moveDown: "انقل الصفحة لأسفل",
  imageFailed: "تعذّر رفع الصورة. الصيغ المقبولة: PNG وJPEG، وحاول مرة أخرى.",
  pagesFailed: "تعذّر تعديل الصفحات. أعد المحاولة.",

  duplicateBoard: "نسخ السبّورة",
  copying: "جارٍ النسخ… ستظهر النسخة في القائمة بعد لحظات.",
  deleteBoard: "حذف السبّورة",
  confirmDeleteBoard: "اضغط مرة أخرى لحذف السبّورة نهائياً",

  /** The save indicator's five states (US2). */
  saveState: {
    saved: "محفوظ",
    saving: "جارٍ الحفظ…",
    offline: "بدون اتصال — محفوظ على هذا الجهاز",
    failed: "تعذّر الحفظ",
    unprotected: "محفوظ — الحماية من الانقطاع غير متاحة في هذا المتصفّح",
  },

  readOnly: "للقراءة فقط",
  editingNow: (name: string) => `يحرّر الآن: ${name}`,
  takeEditing: "خُذ التحرير",
  takingEditing: "ينتقل التحرير إليك خلال ثوانٍ…",
  handoverNotice: "طلب مدرّس الكورس التحرير. تُحفظ تغييراتك ثم تصير السبّورة للقراءة فقط.",

  conflictTitle: "هذه الصفحة تغيّرت في مكان آخر",
  conflictMessage: "حُفظت نسخة أحدث من هذه الصفحة من تبويب أو جهاز آخر. اختر ما تريد الاحتفاظ به.",
  takeServerCopy: "خذ نسخة الخادم",
  keepMineAsNewPage: "احفظ نسختي كصفحة جديدة",

  restoreTitle: "وُجدت تغييرات لم تُحفظ على هذا الجهاز",
  restoreMessage: "آخر ما رسمته لم يصل إلى الخادم. «إلغاء» يعرض نسخة الخادم ويُبقي نسختك على الجهاز.",
  restoreAskMessage:
    "آخر ما رسمته لم يصل إلى الخادم، والصفحة تغيّرت على الخادم بعده. استعادة نسختك تحلّ محلّ نسخة الخادم. «إلغاء» يعرض نسخة الخادم ويُبقي نسختك على الجهاز.",
  restoreMine: "استعِد تغييراتي",

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
