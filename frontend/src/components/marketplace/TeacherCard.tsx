import { CheckIcon } from "@/components/icons";
import Link from "next/link";
import { counted } from "@/lib/labels";
import type { TeacherCard as Teacher } from "@/lib/public-api";
import { AvailableNowChip, AvailableNowDot } from "./AvailableNow";
import { StarRating } from "./StarRating";
import { TrialCta } from "./TrialCta";
import { TrustScoreBadge } from "./TrustScoreBadge";

function Initials({ name }: { name: string }) {
  const initials = name
    .split(" ")
    .slice(0, 2)
    .map((part) => part.charAt(0))
    .join("");

  return (
    <span
      className="flex h-16 w-16 items-center justify-center rounded-full bg-primary-soft text-lg font-bold text-primary-ink"
      aria-hidden="true"
    >
      {initials}
    </span>
  );
}

export function TeacherCard({ teacher }: { teacher: Teacher }) {
  // slug, falling back to uuid — the API resolves either, so a profile whose
  // slug has not been generated yet still links somewhere real.
  const profileHref = `/teachers/${teacher.slug ?? teacher.uuid}`;

  return (
    <article className="group flex flex-col rounded-3xl border border-line bg-surface-raised p-5 transition duration-200 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg active:translate-y-0 active:duration-100">
      <div className="mb-4 flex items-start gap-4">
        {/* ⚠️ `relative` AND `shrink-0` ON THE WRAPPER, not on the image. The dot
            is absolutely positioned against this box, and the box is what must
            keep its size in a flex row — moving `shrink-0` down to the photo
            would let the wrapper collapse and take the dot with it. */}
        <div className="relative shrink-0">
          {teacher.photo_url ? (
            // A broken image must not collapse the card, so the fallback is the same
            // size as the photo it replaces.
            <img
              src={teacher.photo_url}
              alt=""
              className="h-16 w-16 rounded-full object-cover transition duration-300 ease-out group-hover:scale-105"
              loading="lazy"
            />
          ) : (
            <Initials name={teacher.name} />
          )}

          {teacher.available_now && <AvailableNowDot />}
        </div>

        <div className="min-w-0 flex-1">
          {/* `flex-wrap`: the name is a link that truncates, and the chip beside
              it must not be what forces the truncation on a narrow card. */}
          <h3 className="flex flex-wrap items-center gap-1.5 text-base font-bold text-ink">
            <Link href={profileHref} className="truncate hover:text-primary-ink">
              {teacher.name}
            </Link>
            {teacher.is_verified && (
              <CheckIcon className="h-4 w-4 shrink-0 text-secondary-ink" />
            )}
            {teacher.available_now && <AvailableNowChip />}
          </h3>
          {/* Two lines, not `truncate`. The headline is the teacher's own pitch
              and the only line that tells two maths teachers apart — cutting it
              mid-word at «مدرّس رياضيات وفيزياء للمر…» removed the differentiator
              from every card on the page. Clamped rather than free so a long one
              cannot push the buttons out of alignment across a row. */}
          <p className="line-clamp-2 text-sm leading-snug text-ink-muted">
            {teacher.headline}
          </p>
          <p className="mt-1 text-xs text-ink-muted">
            {counted(teacher.years_experience, {
              // ⚠️ «أقل من سنة»، لا «لا سنوات خبرة». الصفرُ هنا مدرّسٌ مُعتمَدٌ
              // في أوّلِ عامِه، وجملةُ النفيِ تقرأُ حكماً عليه على بطاقةٍ
              // تُعرَضُ في السوق.
              zero: "أقل من سنة خبرة",
              one: "سنة خبرة",
              two: "سنتا خبرة",
              few: "سنوات خبرة",
              many: "سنة خبرة",
              other: "سنة خبرة",
            })}
            {teacher.grade_levels.length > 0 && (
              <> · {teacher.grade_levels.map((level) => level.name).join(" · ")}</>
            )}
          </p>
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <StarRating value={teacher.average_rating} count={teacher.reviews_count} />
        <TrustScoreBadge
          score={teacher.trust_score}
          band={teacher.trust_score_band}
        />
      </div>

      {teacher.subjects.length > 0 && (
        <ul className="mb-4 flex flex-wrap gap-1.5">
          {teacher.subjects.slice(0, 3).map((subject) => (
            <li
              key={subject.slug}
              className="rounded-lg bg-primary-soft px-2 py-0.5 text-xs text-primary-ink"
            >
              {subject.name}
            </li>
          ))}
        </ul>
      )}

      {/* One filled action, one quiet one. Two buttons of equal weight make the
          visitor choose between them before choosing a teacher; the trial is
          what this page is for, and the profile is already reachable from the
          name above. Pills, matching every other control in the world. */}
      <div className="mt-auto flex gap-2 pt-4">
        {/* Role-aware: a signed-in visitor is never sent to a signup form. */}
        <TrialCta teacherUuid={teacher.uuid} />
        <Link
          href={profileHref}
          className="flex-1 rounded-full border border-line px-3 py-2.5 text-center text-sm font-semibold text-ink transition duration-200 ease-out hover:border-primary hover:text-primary-ink active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          عرض الملف
        </Link>
      </div>
    </article>
  );
}
