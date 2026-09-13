import Link from "next/link";
import { lockMessage, type CurriculumLesson, type LessonNeighbours } from "@/lib/curriculum";
import { ChevronEndIcon, ChevronStartIcon, LockIcon } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";

/**
 * الانتقالُ بينَ الدروس: السابقُ والتالي، أسفلَ محتوى الدرس.
 *
 * ⚠️ **«مفتوح» جوابُ الخادمِ لا حسابٌ هنا.** إغراءُ كتابةِ
 * `is_sequential && !completed ⇒ اقفلْ زرَّ التالي` كبيرٌ ومباشر — وهو الخطأ:
 * `Enrollment::accessTo()` يقرِّرُ الفتحَ من التسلسلِ **وبوّابةِ الاختبارِ ومقعدِ
 * تسجيلِ الحصّةِ والمجموعة**، فقاعدةٌ مكتوبةٌ في TypeScript تُخالفُه عندَ أوّلِ
 * تسجيلِ حصّةٍ في المسار: الزرُّ يقولُ «مفتوح» والبابُ يردُّ ‏٤٠٣. `state`
 * و`lock` هما ما يُقرَأ، وهما نفسُ ما ترسمُ به `LessonRow` أقفالَها.
 *
 * ⚠️ **والمقفولُ ليس رابطاً ولا زرّاً معطَّلاً** (٠١٦ · FR-007): رابطٌ يبدو
 * معطَّلاً يبقى قابلاً للنقرِ بلوحةِ المفاتيح، وزرٌّ `disabled` يخرجُ من ترتيبِ
 * التنقّلِ فلا يقرؤه أحدٌ ولا يعرفُ لماذا توقّف. بطاقةٌ نصّيّةٌ تحملُ السببَ
 * مقروءةٌ للجميعِ ولا تَعِدُ بشيء.
 *
 * ⚠️ **والسببُ من الخادمِ أوّلاً** عبرَ `lockMessage`: هو وحدَه يُسمّي العنصرَ
 * الذي يجبُ إكمالُه، وهذا كلُّ معنى FR-043. والاحتياطيُّ في `lib/curriculum.ts`
 * موجودٌ كي لا يُعرَضَ «مقفول» عارياً — وقفلٌ بلا سببٍ تذكرةُ دعم.
 *
 * ⚠️ **والاتّجاهُ يسكنُ اسمَ الأيقونةِ لا قلباً في CSS**: `ChevronStartIcon`
 * للتالي و`ChevronEndIcon` للسابق، تماماً كتصفيحِ المدوّنة.
 */
export function LessonNav({ neighbours }: { neighbours: LessonNeighbours }) {
  const { previous, next, position, total } = neighbours;

  // درسٌ لا يعرفُ الكورسُ موضعَه لا جارَ له يُعرَض.
  if (position === 0) return null;

  return (
    <nav
      aria-label="الانتقال بين الدروس"
      className="animate-float-in mt-2 flex flex-col gap-4 border-t border-line pt-6"
    >
      <p className="text-center text-sm font-semibold text-ink-muted tabular-nums">
        الدرس {arabicNumber(position)} من {arabicNumber(total)}
      </p>

      <div className="grid gap-3 sm:grid-cols-2">
        <NavTile lesson={previous} direction="previous" />
        <NavTile lesson={next} direction="next" />
      </div>
    </nav>
  );
}

function NavTile({
  lesson,
  direction,
}: {
  lesson: CurriculumLesson | null;
  direction: "previous" | "next";
}) {
  const isNext = direction === "next";
  const label = isNext ? "الدرس التالي" : "الدرس السابق";
  const Icon = isNext ? ChevronStartIcon : ChevronEndIcon;

  // الطرفُ: أوّلُ درسٍ لا سابقَ له وآخرُه لا تاليَ. مساحةٌ محجوزةٌ تُبقي
  // الآخرَ في مكانِه بدلَ أن يقفزَ إلى منتصفِ الصفّ.
  if (lesson === null) {
    return (
      <p className="hidden rounded-2xl border border-dashed border-line px-4 py-3 text-sm text-ink-muted sm:block">
        {isNext ? "هذا آخر درس في الكورس." : "هذا أول درس في الكورس."}
      </p>
    );
  }

  if (lesson.state === "locked") {
    return (
      <div className="rounded-2xl border border-line bg-surface px-4 py-3">
        <p className="flex items-center gap-2 text-xs font-bold text-ink-muted">
          <LockIcon className="h-4 w-4" aria-hidden="true" />
          {label} — مقفول
        </p>
        <p className="mt-1 truncate text-sm font-semibold text-ink-muted">{lesson.title}</p>
        <p className="mt-1 text-xs leading-relaxed text-ink-muted">{lockMessage(lesson.lock)}</p>
      </div>
    );
  }

  return (
    <Link
      href={`/learn/${lesson.uuid}`}
      className={`group flex flex-col rounded-2xl border border-line bg-surface-raised px-4 py-3 transition duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md active:translate-y-0 active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
        isNext ? "sm:text-start" : "sm:text-end"
      }`}
    >
      <span
        className={`flex items-center gap-1.5 text-xs font-bold text-primary-ink ${isNext ? "" : "sm:flex-row-reverse"}`}
      >
        <Icon
          className={`h-4 w-4 transition-transform duration-200 ${isNext ? "group-hover:-translate-x-1" : "group-hover:translate-x-1"}`}
          aria-hidden="true"
        />
        {label}
      </span>
      <span className="mt-1 truncate text-sm font-semibold text-ink">{lesson.title}</span>
      <span className="mt-0.5 text-xs text-ink-muted">{lesson.type_label}</span>
    </Link>
  );
}
