import { CheckIcon } from "@/components/icons";
import Link from "next/link";
import type { TeacherCard as Teacher } from "@/lib/public-api";
import { StarRating } from "./StarRating";
import { TrustScoreBadge } from "./TrustScoreBadge";

function Initials({ name }: { name: string }) {
  const initials = name
    .split(" ")
    .slice(0, 2)
    .map((part) => part.charAt(0))
    .join("");

  return (
    <span
      className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary-soft text-lg font-bold text-primary-ink"
      aria-hidden="true"
    >
      {initials}
    </span>
  );
}

export function TeacherCard({ teacher }: { teacher: Teacher }) {
  const profileHref = `/teachers/${teacher.uuid}`;

  return (
    <article className="flex flex-col rounded-2xl border border-line bg-surface-raised p-5 transition hover:border-primary/40 hover:shadow-lg">
      <div className="mb-4 flex items-start gap-4">
        {teacher.photo_url ? (
          // A broken image must not collapse the card, so the fallback is the same
          // size as the photo it replaces.
          <img
            src={teacher.photo_url}
            alt=""
            className="h-16 w-16 shrink-0 rounded-full object-cover"
            loading="lazy"
          />
        ) : (
          <Initials name={teacher.name} />
        )}

        <div className="min-w-0 flex-1">
          <h3 className="flex items-center gap-1.5 text-base font-bold text-ink">
            <Link href={profileHref} className="truncate hover:text-primary-ink">
              {teacher.name}
            </Link>
            {teacher.is_verified && (
              <CheckIcon className="h-4 w-4 shrink-0 text-secondary-ink" />
            )}
          </h3>
          <p className="truncate text-sm text-ink-muted">{teacher.headline}</p>
          <p className="mt-1 text-xs text-ink-muted">
            {teacher.years_experience} سنوات خبرة
          </p>
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <StarRating value={teacher.average_rating} count={teacher.reviews_count} />
        <TrustScoreBadge
          score={teacher.trust_score}
          band={teacher.trust_score_band}
        />
        {teacher.available_now && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-secondary/15 px-2.5 py-1 text-xs font-semibold text-secondary-ink">
            <span className="h-1.5 w-1.5 rounded-full bg-secondary" aria-hidden="true" />
            متاح الآن
          </span>
        )}
      </div>

      {teacher.subjects.length > 0 && (
        <ul className="mb-4 flex flex-wrap gap-1.5">
          {teacher.subjects.slice(0, 3).map((subject) => (
            <li
              key={subject.slug}
              className="rounded-lg bg-primary-soft px-2 py-0.5 text-xs text-primary-ink"
            >
              {subject.name_ar}
            </li>
          ))}
        </ul>
      )}

      <div className="mt-auto flex gap-2 pt-4">
        <Link
          href={profileHref}
          className="flex-1 rounded-xl border border-line px-3 py-2.5 text-center text-sm font-semibold text-ink transition hover:border-primary hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          عرض الملف
        </Link>
        <Link
          href={`/signup/student?teacher=${teacher.uuid}`}
          className="flex-1 rounded-xl bg-accent px-3 py-2.5 text-center text-sm font-semibold text-accent-foreground transition hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >
          حصة تجريبية
        </Link>
      </div>
    </article>
  );
}
