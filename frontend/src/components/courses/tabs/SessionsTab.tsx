"use client";

import { SessionCard } from "@/components/sessions/SessionCard";
import { Button } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/states/EmptyState";
import type { ClassSession } from "@/lib/class-sessions";

/**
 * This course's lessons, ahead and behind (US2 · FR-016).
 *
 * ⚠️ THE RECORDING IS OFFERED FROM THE PAST ROW, AND THAT LINK IS THE WHOLE
 * POINT OF THE SPLIT. A published recording IS a lesson, and until 018 the only
 * way to it was a link nobody had drawn — «publishing a recording and not saying
 * where it is has already cost this product a whole phase». `lesson_uuid` is
 * null until the ingest lands, so the button is absent rather than dead.
 *
 * ⚠️ AND A RECORDING IS ENTITLED BY THE SEAT, NOT BY THE COURSE SEQUENCE — which
 * is why the row draws no link when there is none to draw, and says nothing
 * about why. The gate refuses `NO_SEAT`, meaning «later» rather than «not
 * yours», and this list has no way to ask it per row without a query per card.
 * A student who was in the lesson has the button; one who was not sees the
 * session without it. Naming the reason here is the upgrade, and it needs the
 * entitlement stamped in bulk the way `UnlockReader::stamp()` does.
 */
export function SessionsTab({ sessions }: { sessions: ClassSession[] }) {
  const now = Date.now();

  // One pass, and `ends_at` rather than `starts_at`: a lesson in progress belongs
  // with the upcoming ones, which is where the join button is.
  const upcoming: ClassSession[] = [];
  const past: ClassSession[] = [];

  for (const session of sessions) {
    (Date.parse(session.ends_at) >= now ? upcoming : past).push(session);
  }

  upcoming.sort((a, b) => Date.parse(a.starts_at) - Date.parse(b.starts_at));
  past.sort((a, b) => Date.parse(b.starts_at) - Date.parse(a.starts_at));

  if (sessions.length === 0) {
    return (
      <EmptyState
        title="لا حصص في هذه المادّة بعد"
        description="حين يجدول مدرّسك حصّة ستظهر هنا بموعدها ومقاعدها."
      />
    );
  }

  return (
    <div className="space-y-8">
      {upcoming.length > 0 && (
        <Group title="القادمة" sessions={upcoming} />
      )}
      {past.length > 0 && <Group title="الماضية" sessions={past} past />}
    </div>
  );
}

function Group({
  title,
  sessions,
  past = false,
}: {
  title: string;
  sessions: ClassSession[];
  past?: boolean;
}) {
  return (
    <section className="space-y-3">
      <h3 className="flex items-center gap-3 text-sm font-semibold text-ink-muted">
        <span>{title}</span>
        <span aria-hidden className="h-px flex-1 bg-line" />
        <span className="text-xs font-normal">
          <bdi>{sessions.length}</bdi> حصة
        </span>
      </h3>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        {sessions.map((session, index) => (
          /*
            ⚠️ THE STAGGER IS CAPPED. A term of lessons times a per-row delay
            lands the last card many seconds after the first; `banner-rise`
            covers the delay as well as the animation so a card is not painted,
            hidden and repainted, and the reduced-motion block in `globals.css`
            zeroes all of it (FR-012).
          */
          <div
            key={session.uuid}
            className="banner-rise"
            style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
          >
            <SessionCard
              session={session}
              href={`/sessions/${session.uuid}/room`}
              time="full"
              action={past ? <RecordingLink session={session} /> : undefined}
            />
          </div>
        ))}
      </div>
    </section>
  );
}

function RecordingLink({ session }: { session: ClassSession }) {
  const lessonUuid = session.recording?.lesson_uuid ?? null;

  if (lessonUuid === null) return null;

  return (
    <Button href={`/learn/${lessonUuid}`} size="sm" variant="secondary">
      شاهد التسجيل
    </Button>
  );
}
