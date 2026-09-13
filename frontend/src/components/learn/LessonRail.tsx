import Link from "next/link";
import {
  flattenLessons,
  lockMessage,
  type Curriculum,
  type CurriculumLesson,
} from "@/lib/curriculum";
import { CheckIcon, ListIcon, LockIcon } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";
import { counted } from "@/lib/labels";

/**
 * الكورسُ بجانبِ الدرس: اسمُه، وكم أُنجِزَ منه، وأينَ يقفُ القارئُ الآن.
 *
 * ⚠️ **ليس عارضَ منهجٍ ثانياً.** `CurriculumTree` يرسمُ المنهجَ كاملاً في صفحةِ
 * الكورس، وهذا شريطٌ مضغوطٌ بعرضِ عمودٍ جانبيّ — والتحذيرُ المكتوبُ في
 * `CourseCurriculum` عن «عارضَينِ للمنهج» عن **القرارات** لا عن الكثافة: الأقفالُ
 * والأسبابُ والعناوينُ هنا كلُّها من الحمولةِ نفسِها (`state` · `lock` · `uuid`)،
 * ولا سطرَ واحدَ يُقرِّرُ شيئاً من عندِه.
 *
 * ⚠️ **والصفُّ المقفولُ ليس رابطاً** (٠١٦ · FR-007)، بنفسِ قاعدةِ `LessonRow`
 * حرفاً بحرف: `state === "locked"` ⇒ لا `<Link>` ولا `<button>`.
 *
 * ⚠️ **والنِّسَبُ والأعدادُ من الخادم.** `completed_count` و`countable_count`
 * يأتيانِ من `CourseProgress` — وهو المقامُ نفسُه الذي تقرؤه الشهادةُ وحدثُ
 * إتمامِ الكورس. عَدُّ الصفوفِ المكتملةِ هنا مقامٌ ثانٍ يفترقُ عنه عندَ أوّلِ شرطٍ
 * يُضافُ إليه، فيرى الطالبُ نسبةً لا يوافقُها شيءٌ في المنتَج.
 */
export function LessonRail({
  tree,
  currentUuid,
}: {
  tree: Curriculum;
  currentUuid: string;
}) {
  const { course } = tree;
  const flat = flattenLessons(tree);
  const pct = Math.max(0, Math.min(100, Math.round(course.progress_pct)));

  return (
    <aside className="flex flex-col gap-4">
      <div className="rounded-3xl border border-line bg-surface-raised p-5">
        <Link
          href={`/enrollments/${course.uuid}`}
          className="text-base font-bold leading-snug text-ink hover:text-primary-ink hover:underline"
        >
          {course.title}
        </Link>

        {course.teacher_name !== null && (
          <p className="mt-1 text-xs text-ink-muted">{course.teacher_name}</p>
        )}

        <div className="mt-4">
          <div className="mb-1.5 flex items-center justify-between text-xs font-semibold">
            <span className="text-ink-muted">
              {counted(course.completed_count, {
                one: "درس مكتمل",
                two: "درسان مكتملان",
                few: "دروس مكتملة",
                many: "درساً مكتملاً",
                other: "درس مكتمل",
                zero: "لم يكتمل درس بعد",
              })}
            </span>
            <span className="text-primary-ink tabular-nums">{arabicNumber(pct)}٪</span>
          </div>

          {/*
            ⚠️ `role="progressbar"` مع `aria-valuenow`: الشريطُ لونٌ وعرضٌ، ولا
            شيءَ منهما يصلُ قارئَ الشاشة. والعرضُ محصورٌ بينَ ٠ و١٠٠ فوقَ ذلك:
            نسبةٌ خارجَ المدى تخرجُ من الإطارِ وتكسرُ الصفَّ كلَّه.
          */}
          <div
            role="progressbar"
            aria-valuenow={pct}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label="نسبة إتمام الكورس"
            className="h-2 overflow-hidden rounded-full bg-primary-soft"
          >
            <div
              className="h-full rounded-full bg-primary transition-[width] duration-500 ease-out"
              style={{ width: `${pct}%` }}
            />
          </div>
        </div>
      </div>

      <div className="rounded-3xl border border-line bg-surface-raised p-2">
        <h2 className="flex items-center gap-2 px-3 py-2 text-sm font-bold text-ink">
          <ListIcon className="h-4 w-4 text-primary-ink" aria-hidden="true" />
          محتويات الكورس
        </h2>

        {/*
          مفرودٌ لا مُعشَّشٌ في العمودِ الجانبيّ: عناوينُ الأقسامِ والفصولِ فوقَ
          كلِّ صفٍّ تأكلُ عرضَ ‏٢٠rem وتترُكُ للعنوانِ سطرَ حرفَين. والترتيبُ هو
          ترتيبُ الخادمِ كما وصل.
        */}
        <ol className="flex flex-col">
          {flat.map((lesson, index) => (
            <li key={lesson.uuid}>
              <RailRow
                lesson={lesson}
                position={index + 1}
                current={lesson.uuid === currentUuid}
              />
            </li>
          ))}
        </ol>
      </div>
    </aside>
  );
}

function RailRow({
  lesson,
  position,
  current,
}: {
  lesson: CurriculumLesson;
  position: number;
  current: boolean;
}) {
  const mark =
    lesson.state === "completed" ? (
      <CheckIcon className="h-4 w-4 text-secondary-ink" aria-hidden="true" />
    ) : lesson.state === "locked" ? (
      <LockIcon className="h-4 w-4 text-ink-muted" aria-hidden="true" />
    ) : (
      <span className="text-xs font-bold text-ink-muted tabular-nums" aria-hidden="true">
        {arabicNumber(position)}
      </span>
    );

  const body = (
    <>
      <span className="flex h-6 w-6 shrink-0 items-center justify-center">{mark}</span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium">{lesson.title}</span>
        {lesson.state === "locked" && (
          // السببُ كاملاً لا مقصوصاً: هو الجملةُ التي تقولُ ماذا يفعلُ الطالب.
          <span className="mt-0.5 block text-xs leading-relaxed text-ink-muted">
            {lockMessage(lesson.lock)}
          </span>
        )}
      </span>
    </>
  );

  const shared = "flex items-start gap-2 rounded-2xl px-3 py-2.5 text-start";

  if (lesson.state === "locked") {
    return <span className={`${shared} text-ink-muted`}>{body}</span>;
  }

  return (
    <Link
      href={`/learn/${lesson.uuid}`}
      // ⚠️ `aria-current` لا لونٌ وحدَه: الصفُّ الحاليُّ مميَّزٌ بالخلفيّةِ للعين،
      // وقارئُ الشاشةِ لا يرى خلفيّة.
      aria-current={current ? "page" : undefined}
      className={`${shared} transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
        current
          ? "bg-primary-soft font-bold text-primary-ink"
          : "text-ink hover:bg-primary-soft/60 hover:text-primary-ink"
      }`}
    >
      {body}
    </Link>
  );
}
