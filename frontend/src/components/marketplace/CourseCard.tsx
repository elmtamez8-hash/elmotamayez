import Link from "next/link";
import { counted, courseTypeLabel } from "@/lib/labels";
import type { CourseCard as Course } from "@/lib/public-api";
import {
  ChevronEndIcon,
  ClockIcon,
  PlayIcon,
  UsersIcon,
  VerifiedBadgeIcon,
} from "@/components/icons";
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

  const href = `/courses/${course.slug ?? course.uuid}`;

  /*
    The bold card (owner request 2026-10-10), in the TEACHER CARD's language: a
    brand-colour cover, the teacher's photo hanging off its lower edge with the
    name beside it on the white, the facts as one row of chips, and ONE accent
    button. A grid of courses and a grid of teachers now read as one product.
  */
  return (
    <article className="group relative flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 active:translate-y-0 active:duration-100 motion-reduce:transition-none motion-reduce:hover:translate-y-0">
      <div className="relative aspect-video overflow-hidden bg-primary">
        <CourseCover
          title={course.title}
          coverUrl={course.cover_url}
          subject={course.subject}
          variant="card"
        />

        {/*
          النوعُ في طرف و«الأكثر طلباً» في الطرفِ الآخَر: أوّلُ ما يفرزُ به
          المتصفّحُ («مباشر» أم «مسجَّل»)، ولا يزاحمُ أحدُهما الآخَر.
        */}
        <span className="absolute top-3 end-3 rounded-full bg-surface-raised/95 px-3 py-1 text-xs font-extrabold text-primary-ink shadow-md">
          {courseTypeLabel(course.type)}
        </span>

        {course.is_bestseller && (
          <span className="absolute top-3 start-3 rounded-full bg-accent px-3 py-1 text-xs font-extrabold text-accent-foreground shadow-md">
            الأكثر طلباً
          </span>
        )}

        {/*
          Spec 040 — the course's «حصة تجريبية». Its own link above the card's
          stretched title link (`relative z-10`), straight to the lesson: the
          badge is a door, not a label. Opposite the teacher's photo, which
          hangs off the start edge.
        */}
        {course.has_trial === true && (
          <Link
            href={`${href}/trial`}
            className="absolute bottom-3 end-3 z-10 inline-flex items-center gap-1 rounded-full bg-accent px-2.5 py-1 text-xs font-extrabold text-accent-foreground shadow-md transition hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            <PlayIcon className="h-3.5 w-3.5" />
            حصة تجريبية مجانية
          </Link>
        )}
      </div>

      <div className="flex flex-1 flex-col px-5 pb-5">
        {course.teacher ? (
          /*
            The teacher card's row: the photo hangs half off the cover, the name
            sits BESIDE it on the white. Above the stretched title link on the
            z-axis, so «who teaches this» stays its own destination.
          */
          <Link
            href={`/teachers/${course.teacher.slug ?? course.teacher.uuid}`}
            className="relative z-10 -mt-7 mb-3 flex w-fit max-w-full items-end gap-2.5 text-sm font-bold text-ink-muted transition-colors hover:text-primary-ink"
          >
            {course.teacher.photo_url ? (
              <img
                src={course.teacher.photo_url}
                alt=""
                className="h-14 w-14 shrink-0 rounded-2xl object-cover shadow-md shadow-primary/20 ring-4 ring-surface-raised"
                loading="lazy"
              />
            ) : (
              <span
                className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-lg font-extrabold text-primary-ink ring-4 ring-surface-raised"
                aria-hidden="true"
              >
                {course.teacher.name.charAt(0)}
              </span>
            )}
            <span className="flex min-w-0 items-center gap-1 pb-1">
              <span className="truncate">{course.teacher.name}</span>
              {/*
                شارةُ التوثيقِ بجوارِ الاسمِ أينما كُتِب. الحقيقةُ واحدةٌ، فإن
                ظهرَت على بطاقةِ المدرّسِ وحدَها بدا الموثَّقُ غيرَ موثَّقٍ على كلِّ
                كورسٍ له.
              */}
              {course.teacher.is_verified && (
                <VerifiedBadgeIcon className="h-4 w-4 shrink-0 text-secondary-ink" title="مدرّس موثّق" />
              )}
            </span>
          </Link>
        ) : (
          <div className="pt-4" />
        )}

        {/* ⚠️ THE WHOLE CARD, IN ONE CLICK (spec 023 · SC-001). The `after:`
            overlay stretches this one link across the article; the teacher row
            and the trial badge sit above it on the z-axis. */}
        <h3 className="line-clamp-2 text-lg font-extrabold leading-snug text-ink transition-colors group-hover:text-primary-ink motion-reduce:transition-none">
          <Link
            href={`${href}${anchor}`}
            className="after:absolute after:inset-0 after:content-[''] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {course.title}
          </Link>
        </h3>

        <div className="mt-3 flex flex-wrap items-center gap-3">
          <StarRating value={course.average_rating} />
          {course.subject && Mark && (
            <span className="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary-ink">
              <Mark className="h-3.5 w-3.5" />
              {course.subject.name}
            </span>
          )}
        </div>

        {/*
          الحقائقُ صفٌّ واحدٌ من الرقائق، كلٌّ بأيقونتِه. ⛔ ولا مدّةَ لكورسٍ بلا
          حصص: العددُ محسوبٌ من الدروسِ المرئيّة والمدّةُ عمودٌ يكتبُه المؤلّف،
          و«لم تُضَف حصص بعد» بجوارِ «٢٤ ساعة» شُوهِدَت على الشاشة.
        */}
        <dl className="mt-4 flex flex-wrap gap-2 text-xs font-semibold text-ink-muted">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1">
            <dt className="sr-only">عدد الحصص</dt>
            <PlayIcon className="h-3.5 w-3.5 text-primary-ink" />
            <dd>
              {counted(course.lessons_count, {
                zero: "لم تُضَف حصص بعد",
                one: "حصة واحدة",
                two: "حصتان",
                few: "حصص",
                many: "حصة",
                other: "حصة",
              })}
            </dd>
          </div>

          {length !== null && course.lessons_count > 0 && (
            <div className="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1">
              <dt className="sr-only">مدة المحتوى</dt>
              <ClockIcon className="h-3.5 w-3.5 text-primary-ink" />
              <dd>{length}</dd>
            </div>
          )}

          <div className="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1">
            <dt className="sr-only">عدد الطلاب</dt>
            <UsersIcon className="h-3.5 w-3.5 text-primary-ink" />
            <dd>
              {counted(course.enrolled_count, {
                zero: "لا طلاب بعد",
                one: "طالب واحد",
                two: "طالبان",
                few: "طلاب",
                many: "طالباً",
                other: "طالب",
              })}
            </dd>
          </div>
        </dl>

        {/*
          ⚠️ زرٌّ في شكلِه، `span` في بنيتِه: الكارتُ كلُّه رابطٌ واحدٌ (رابطُ
          العنوانِ أعلاه)، ورابطٌ ثانٍ هنا يضعُ وجهتَين على سطحٍ واحد. وهو زرُّ
          كارتِ المدرّسِ نفسُه — البرتقاليُّ، الدائريّ.
        */}
        <div className="mt-auto pt-5">
          <span
            aria-hidden="true"
            className="flex items-center justify-center gap-2 rounded-full bg-accent px-4 py-2.5 text-sm font-semibold text-accent-foreground transition duration-200 ease-out group-hover:brightness-105 motion-reduce:transition-none"
          >
            عرض الكورس
            <ChevronEndIcon className="h-4 w-4 transition-transform duration-300 ease-out group-hover:-translate-x-1 motion-reduce:transition-none motion-reduce:group-hover:translate-x-0" />
          </span>
        </div>
      </div>
    </article>
  );
}
