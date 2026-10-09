import { VerifiedBadgeIcon } from "@/components/icons";
import Link from "next/link";
import { counted, YEARS_OF_EXPERIENCE } from "@/lib/labels";
import type { TeacherCard as Teacher } from "@/lib/public-api";
import { AvailableNowChip, AvailableNowDot } from "./AvailableNow";
import { StarRating } from "./StarRating";
import { TrialCta } from "./TrialCta";
import { TrustScoreBadge } from "./TrustScoreBadge";
import { subjectIcon } from "./subject-icon";

function Initials({ name }: { name: string }) {
  const initials = name
    .split(" ")
    .slice(0, 2)
    .map((part) => part.charAt(0))
    .join("");

  return (
    <span
      className="flex h-20 w-20 items-center justify-center rounded-2xl bg-primary-soft text-2xl font-extrabold text-primary-ink ring-4 ring-surface-raised"
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

  // The first subject's drawing rides the cover, faint — the same shared map the
  // subject grid and the profile chips draw from, never a second one.
  const CoverIcon = teacher.subjects[0] ? subjectIcon(teacher.subjects[0]) : null;

  return (
    <article className="group flex flex-col overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm transition duration-300 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 active:translate-y-0 active:duration-100 motion-reduce:transition-none motion-reduce:hover:translate-y-0">
      {/* The cover: the wordmark's square dots on the brand colour, so a row of
          cards reads as one product rather than a row of white boxes. Purely
          decorative — nothing a reader needs is written on it. */}
      <div
        className="bg-squares relative isolate h-20 overflow-hidden bg-primary"
        aria-hidden="true"
      >
        {CoverIcon && (
          <CoverIcon className="absolute -bottom-6 end-3 -z-10 h-28 w-28 text-white/15 transition duration-500 ease-out group-hover:-translate-y-1 group-hover:-rotate-6 motion-reduce:transition-none motion-reduce:group-hover:translate-y-0 motion-reduce:group-hover:rotate-0" />
        )}
      </div>

      <div className="flex flex-1 flex-col px-5 pb-5">
        <div className="-mt-10 mb-4 flex">
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
                className="h-20 w-20 rounded-2xl object-cover shadow-lg shadow-primary/20 ring-4 ring-surface-raised transition duration-300 ease-out group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                loading="lazy"
              />
            ) : (
              <Initials name={teacher.name} />
            )}

            {teacher.available_now && <AvailableNowDot />}
          </div>
        </div>

        {/* `flex-wrap`: the name is a link that truncates, and the chip beside
            it must not be what forces the truncation on a narrow card. */}
        <h3 className="mb-1 flex flex-wrap items-center gap-1.5 text-lg font-extrabold text-ink">
          <Link href={profileHref} className="truncate transition-colors hover:text-primary-ink">
            {teacher.name}
          </Link>
          {teacher.is_verified && (
            <VerifiedBadgeIcon className="h-[1.1rem] w-[1.1rem] shrink-0 text-secondary-ink" title="مدرّس موثّق" />
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
        <p className="mt-2 text-xs font-semibold text-ink-muted">
          {counted(teacher.years_experience, YEARS_OF_EXPERIENCE)}
          {teacher.grade_levels.length > 0 && (
            <> · {teacher.grade_levels.map((level) => level.name).join(" · ")}</>
          )}
        </p>

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <StarRating value={teacher.average_rating} count={teacher.reviews_count} />
          <TrustScoreBadge
            score={teacher.trust_score}
            band={teacher.trust_score_band}
          />
        </div>

        {teacher.subjects.length > 0 && (
          <ul className="mt-3 flex flex-wrap gap-1.5">
            {teacher.subjects.slice(0, 3).map((subject) => {
              const Icon = subjectIcon(subject);

              return (
                <li
                  key={subject.slug}
                  className="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary-ink"
                >
                  <Icon className="h-3.5 w-3.5" />
                  {subject.name}
                </li>
              );
            })}
          </ul>
        )}

        {/* One filled action, one quiet one. Two buttons of equal weight make the
            visitor choose between them before choosing a teacher; the trial is
            what this page is for, and the profile is already reachable from the
            name above. Pills, matching every other control in the world. */}
        <div className="mt-auto flex gap-2 pt-5">
          {/* Role-aware: a signed-in visitor is never sent to a signup form. */}
          <TrialCta teacherUuid={teacher.uuid} />
          <Link
            href={profileHref}
            className="flex-1 rounded-full border border-line px-3 py-2.5 text-center text-sm font-semibold text-ink transition duration-200 ease-out hover:border-primary hover:bg-primary-soft hover:text-primary-ink active:scale-[0.97] active:duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            عرض الملف
          </Link>
        </div>
      </div>
    </article>
  );
}
