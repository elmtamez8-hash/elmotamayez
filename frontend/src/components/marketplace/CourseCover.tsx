import { AcademicCapIcon } from "@/components/icons";
import type { Taxonomy } from "@/lib/public-api";
import { subjectIcon } from "./subject-icon";

/**
 * The course's cover — the teacher's image where there is one, and a generated
 * panel where there is not.
 *
 * ⛔ WHAT THIS REPLACES WAS READ AS BROKEN DATA, NOT AS A PLACEHOLDER. Both
 * surfaces drew the title's FIRST LETTER over a flat ground — `text-4xl` at 30%
 * ink on the card, `text-7xl` white on the hero — and a marketplace of those is
 * a page that looks like it failed to load. It is also two spellings of one
 * decision: two sizes, two tones, two branches, drifting from the first edit.
 *
 * ⚠️ AND THE VARIANT IS A CLOSED SET, NOT A `className`. Shared UI here takes no
 * free-form class (the repository's own rule), and deleting both letter branches
 * is what made `tsc` name the two call sites — the `PLATFORM_NAME` technique
 * this tree uses whenever one decision is spelled in several places.
 *
 * ⚠️ `aria-hidden` ON THE WHOLE THING, AND IT COSTS NOTHING. Everything the
 * generated panel draws — the subject, and on the hero the course itself — is
 * written again in text directly below it on both surfaces. A decorative band is
 * what this is; announcing it would read the same course twice.
 *
 * ⚠️ NO `next/image`, DELIBERATELY. It routes a path through `sharp`, whose
 * advisories this tree accepts precisely because no user-supplied image reaches
 * it — and a cover is exactly a user-supplied image. The existing comment at the
 * hero said so; it moves here with the markup.
 */
export function CourseCover({
  title,
  coverUrl,
  subject,
  variant,
}: {
  title: string;
  coverUrl: string | null;
  /** Absent where the payload does not carry it; the mark falls back. */
  subject?: Taxonomy | null;
  variant: "card" | "hero";
}) {
  if (coverUrl !== null) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={coverUrl}
        alt=""
        className={
          variant === "card"
            ? // The cover is what the card is ABOUT, so it is the thing that
              // moves. 400ms and a 4% scale: slow and small enough to read as
              // the image breathing, not as a zoom effect applied to a photo.
              "h-full w-full object-cover transition duration-[400ms] ease-out group-hover:scale-[1.04]"
            : "h-full w-full object-cover"
        }
        loading={variant === "card" ? "lazy" : undefined}
      />
    );
  }

  /*
    ⚠️ `subjectIcon()`, NEVER `BY_SLUG` — the resolver reads the slug AND the
    operator's `subjects.icon` lever, and that file exists because a second map
    copied beside it would draw a physics mark on the subjects grid and a
    graduation cap here, with no error anywhere. Its own docblock says so.

    A course with no subject at all keeps a mark rather than an empty box: the
    column is nullable, and «no subject» is a course somebody has not filed yet,
    not a course with nothing in it.
  */
  const Mark = subject ? subjectIcon(subject) : AcademicCapIcon;

  return (
    <div
      aria-hidden="true"
      className={`flex h-full w-full flex-col items-center justify-center gap-2 ${
        variant === "card" ? "bg-primary-soft" : "bg-primary"
      }`}
    >
      <Mark
        className={variant === "card" ? "h-14 w-14 text-primary-ink/30" : "h-24 w-24 text-white/25"}
      />

      {/*
        ⛔ **ولا اسمَ للمادّةِ هنا، وقد كانَ.** الكارتُ يحملُ شريحةَ المادّةِ
        بعرضِه كاملاً على بُعدِ مئةِ بكسلٍ أسفلَ هذا الصندوق، فكتابةُ الكلمةِ
        مرّتَينِ في لقطةٍ واحدةٍ حشوٌ يُقرَأُ خطأً في البيانات. العلامةُ تكفي:
        هي التي تُفرِّقُ كورساً عن كورسٍ في شبكةٍ من تسعة.
      */}
      {/*
        وعلى الشريطِ العريضِ العنوانُ نفسُه — لأنّ البديلَ فراغٌ بعرضِ الصفحة.
        والعنوانُ مكتوبٌ تحتَه في `h1`، فهذه نسخةٌ زخرفيّةٌ لا يقرؤُها أحدٌ
        بأذنِه: `aria-hidden` على الصندوقِ كلِّه أعلاه.
      */}
      {variant === "hero" && (
        <span className="line-clamp-2 max-w-3xl text-balance px-6 text-center text-xl font-black text-white/90 sm:text-2xl">
          {title}
        </span>
      )}
    </div>
  );
}
