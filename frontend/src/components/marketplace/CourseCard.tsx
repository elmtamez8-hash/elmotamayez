import Link from "next/link";
import { counted, courseTypeLabel } from "@/lib/labels";
import type { CourseCard as Course } from "@/lib/public-api";
import { ClockIcon, PlayIcon, UsersIcon } from "@/components/icons";
import { CourseCover } from "./CourseCover";
import { subjectIcon } from "./subject-icon";
import { StarRating } from "./StarRating";

function hours(seconds: number): string {
  const value = Math.round(seconds / 3600);

  // «—» rather than «لا ساعات»: a course with no declared duration has not
  // been measured, which is a different fact from one that lasts no time.
  if (value <= 0) return "—";

  return counted(value, {
    one: "ساعة",
    two: "ساعتان",
    few: "ساعات",
    many: "ساعة",
    other: "ساعة",
  });
}

/*
 * ⚠️ No price, and no discount badge with it (spec 006, FR-021هـ · T089أ).
 *
 * A card in a list is a browsing surface; the price belongs on the buyable unit,
 * which is the course's own page. The API stopped sending both fields, so the
 * badge could not be rendered here even if the rule changed back — it would need
 * the payload to change first, which is the right order.
 */
/**
 * @param anchor A fragment appended to the card's own link, e.g. `#groups`.
 *
 * ⚠️ ONLY THE TITLE LINK TAKES IT. The byline below points at the TEACHER, and
 * a `#groups` glued to that href would send a reader to a fragment that does not
 * exist on the profile — a link that silently does nothing, which is worse than
 * one that goes somewhere wrong. Default empty, so the marketplace listing and
 * the profile's courses tab are byte-identical to what they were.
 */
export function CourseCard({ course, anchor = "" }: { course: Course; anchor?: string }) {
  /*
    ⚠️ «—» هي جوابُ `hours()` لكورسٍ لم تُقَسْ مدّتُه، وهي هنا `null` لا سطر:
    أيقونةُ ساعةٍ أمامَ شَرطةٍ تقولُ «فيه حقلٌ لم يُملأ»، والغيابُ لا يقولُ شيئاً
    وهو الصحيح. والحسابُ مرّةً واحدةً لا مرّتَين — الشرطُ والقيمةُ سؤالٌ واحد.
  */
  const measured = hours(course.duration_seconds);
  const length = measured === "—" ? null : measured;

  // العلامةُ نفسُها التي يرسمُها الغلاف، من المُحلّلِ المشترَكِ لا من خريطةٍ ثانية.
  const Mark = course.subject ? subjectIcon(course.subject) : null;

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised transition duration-200 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg active:translate-y-0 active:duration-100">
      <div className="relative aspect-video bg-primary-soft">
        <CourseCover
          title={course.title}
          coverUrl={course.cover_url}
          subject={course.subject}
          variant="card"
        />

        {course.is_bestseller && (
          <span className="absolute top-3 start-3 rounded-full bg-accent px-2.5 py-1 text-xs font-bold text-accent-foreground">
            الأكثر طلباً
          </span>
        )}

        {/*
          النوعُ على الغلافِ لا في سطرِ البيانات: هو أوّلُ ما يفرزُ به المتصفّحُ
          («مباشر» أم «مسجَّل»)، وفي طرفٍ لا يزاحمُ «الأكثر طلباً» في الطرفِ الآخَر.
        */}
        <span className="absolute top-3 end-3 rounded-full bg-surface-raised/95 px-2.5 py-1 text-xs font-bold text-primary-ink shadow-sm">
          {courseTypeLabel(course.type)}
        </span>

        {/*
          ⚠️ **التقييمُ فوقَ الصورةِ خلفَ حاجب، لا بجوارِها على الأبيض.**
          نصٌّ فوقَ صورةٍ بلا حاجبٍ لا ضمانَ لتباينِه إطلاقاً: النجمةُ نفسُها
          ١٢:١ فوقَ ركنٍ داكنٍ و١٫٤:١ فوقَ ركنٍ فاتح، وأيُّ ركنٍ تقعُ عليه قرارُ
          القَصِّ لا قرارُنا. والحاجبُ الأسودُ يُخرِجُ الصورةَ من حسابِ التباينِ
          حيثُ تقعُ الكلمات — وهي التهجئةُ المقيسةُ في `PageBanner` بحرفِها.

          والتدرّجُ إلى الشفافِ لا مستطيلٌ مصمت: شريطٌ صلبٌ يقطعُ الصورةَ بخطٍّ
          ويُقرَأُ عنصراً آخَرَ فوقَها، والتدرّجُ يُقرَأُ ظلَّها.
        */}
        <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 via-black/35 to-transparent px-4 pb-3 pt-8">
          <StarRating value={course.average_rating} tone="overlay" />
        </div>
      </div>

      <div className="flex flex-1 flex-col gap-3 p-5">

        {/* ⚠️ THE WHOLE CARD, IN ONE CLICK (spec 023 · SC-001).
            The title used to lead to the teacher's courses tab, which cost three
            clicks to reach the course it was already naming — and the card body
            led nowhere at all, so most of the surface a thumb lands on did
            nothing. The `after:` overlay stretches this one link across the
            article; the byline below sits above it on the z-axis so «who teaches
            this» stays a separate destination rather than being swallowed. */}
        <h3 className="text-base font-bold leading-snug text-ink">
          <Link
            href={`/courses/${course.slug ?? course.uuid}${anchor}`}
            className="after:absolute after:inset-0 after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {course.title}
          </Link>
        </h3>

        {course.teacher && (
          <Link
            href={`/teachers/${course.teacher.slug ?? course.teacher.uuid}`}
            className="relative z-10 flex w-fit items-center gap-2 text-sm text-ink-muted hover:text-primary-ink"
          >
            {course.teacher.photo_url ? (
              <img
                src={course.teacher.photo_url}
                alt=""
                className="h-6 w-6 rounded-full object-cover"
                loading="lazy"
              />
            ) : (
              <span
                className="flex h-6 w-6 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary-ink"
                aria-hidden="true"
              >
                {course.teacher.name.charAt(0)}
              </span>
            )}
            {course.teacher.name}
          </Link>
        )}

        {/*
          ⚠️ سطرٌ لكلِّ حقيقة، لا حقائقُ مرصوصةٌ بفواصلَ في سطرٍ واحد.
          «٦ حصص · ساعتان» كانت تُقرَأُ جملةً واحدةً غامضة؛ والعينُ تمسحُ عموداً
          من الأيقوناتِ أسرعَ ممّا تفكُّ سطراً مضغوطاً — وهو ما يفعلُه كارتُ
          المنافسِ بأربعةِ أسطر.

          ⚠️ والصفرُ يسقطُ: `hours()` تُعيدُ «—» لكورسٍ لم تُقَسْ مدّتُه، وسطرٌ
          كاملٌ بأيقونةِ ساعةٍ أمامَ شَرطةٍ أسوأُ من غيابِه.
        */}
        <dl className="flex flex-col gap-1.5 text-xs text-ink-muted">
          <dt className="sr-only">عدد الحصص</dt>
          <dd className="flex items-center gap-2">
            <PlayIcon className="h-4 w-4 shrink-0 text-primary-ink/70" />
            {counted(course.lessons_count, {
              zero: "لم تُضَف حصص بعد",
              one: "حصة واحدة",
              two: "حصتان",
              few: "حصص",
              many: "حصة",
              other: "حصة",
            })}
          </dd>

          {/*
            ⛔ **ولا مدّةَ لكورسٍ بلا حصص، وقد شُوهِدَ العكسُ على الشاشة.**
            «الرياضيات للثانوية العامة» كانت تقولُ «لم تُضَف حصص بعد» و«٢٤ ساعة»
            في سطرَين متتاليَين — وكلاهما صادقٌ عن مصدرِه: العددُ مشتقٌّ
            بـ`withCount` من صفوفِ الدروسِ المرئيّة، والمدّةُ **عمودٌ يكتبُه
            المؤلّفُ بيدِه** على صفِّ الكورس. فالتناقضُ ليس في الكارتِ بل في
            جمعِ رقمٍ محسوبٍ ورقمٍ مُعلَنٍ بلا شرطٍ بينَهما.

            ومحتوىً مُعلَنٌ بأربعٍ وعشرينَ ساعةً خلفَ كورسٍ فارغٍ وعدٌ لا يُوفَّى،
            فالسطرُ يسقطُ حتّى يوجدَ ما يُقاس. والعمودُ لا يُمَسُّ: هو تصريحُ
            المدرّسِ عن كورسِه، وتصحيحُه من هنا كتابةٌ في بياناتِ غيرِنا.
          */}
          {length !== null && course.lessons_count > 0 && (
            <>
              <dt className="sr-only">مدة المحتوى</dt>
              <dd className="flex items-center gap-2">
                <ClockIcon className="h-4 w-4 shrink-0 text-primary-ink/70" />
                {length}
              </dd>
            </>
          )}

          <dt className="sr-only">عدد الطلاب</dt>
          <dd className="flex items-center gap-2">
            <UsersIcon className="h-4 w-4 shrink-0 text-primary-ink/70" />
            {counted(course.enrolled_count, {
              zero: "لا طلاب بعد",
              one: "طالب واحد",
              two: "طالبان",
              few: "طلاب",
              many: "طالباً",
              other: "طالب",
            })}
          </dd>
        </dl>

        {/*
          شريحةُ المادّةِ بعرضِ الكارتِ كاملاً، بالعلامةِ نفسِها التي يرسمُها
          الغلافُ — `subjectIcon()` مرّةً أخرى، لا خريطةً ثانية. وتغيبُ كلّيّاً
          لكورسٍ بلا مادّة: صندوقٌ رماديٌّ فارغٌ يُقرَأُ حقلاً لم يُحمَّل.
        */}
        {course.subject && Mark && (
          <p className="flex items-center gap-2 rounded-xl bg-surface px-3 py-2 text-xs font-semibold text-ink">
            <Mark className="h-4 w-4 shrink-0 text-primary-ink/70" />
            {course.subject.name}
          </p>
        )}

        {/*
          ⚠️ زرٌّ في شكلِه، `span` في بنيتِه — والفرقُ مقصود. الكارتُ كلُّه رابطٌ
          واحدٌ منذُ ٠٢٣ · SC-001 (`after:inset-0` فوق العنوان)، ورابطٌ ثانٍ هنا
          يضعُ وجهتَين على سطحٍ واحدٍ ويكسرُ تنقّلَ لوحةِ المفاتيح: مقصدانِ
          لإصبعٍ واحد. فالتركيزُ يبقى على رابطِ العنوانِ الذي يغطّي الكارتَ كلَّه،
          وهذا نداءٌ مرئيٌّ لا هدفٌ ثانٍ.
        */}
        <span
          aria-hidden="true"
          className="mt-auto block rounded-xl bg-primary px-4 py-2.5 text-center text-sm font-bold text-white transition group-hover:brightness-110"
        >
          عرض التفاصيل والسعر
        </span>
      </div>
    </article>
  );
}
