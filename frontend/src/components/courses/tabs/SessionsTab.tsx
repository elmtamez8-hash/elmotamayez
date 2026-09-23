"use client";

import { useState } from "react";

import { CancelBookingButton } from "@/components/sessions/CancelBookingButton";
import { SessionCard } from "@/components/sessions/SessionCard";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ApiError } from "@/lib/api";
import { classSessions, type ClassSession } from "@/lib/class-sessions";
import { errorCode, userMessage } from "@/lib/errors";

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
 *
 * ⛔ **AN EMPTY LIST HAS TWO CAUSES AND ONLY ONE OF THEM IS THE TEACHER'S.**
 * This tab is drawn from `has_sessions`, which answers «does this course have
 * ANY session» — while the list itself is narrowed by `CohortSessionVisibility`
 * to the groups the reader belongs to. So a student not yet placed in a group,
 * on a course with a full timetable, was shown «لا حصص في هذه المادّة بعد —
 * حين يجدول مدرّسك حصّة ستظهر هنا»: a sentence that is FALSE and blames a
 * teacher who has scheduled every one of them. Owner-approved 2026-09-16 — say
 * what is actually true.
 *
 * ⚠️ **THE REASON IS READ, NEVER RE-DERIVED.** `unplacedReason` is
 * `cohort_gate.message` straight off the curriculum payload — non-null exactly
 * when the course runs in groups and this reader is in none, and it is the
 * server that picks between «a group is open, join it or wait to be placed» and
 * «no group is open yet». Spelling that condition again in TypeScript is the
 * two-spellings defect `cohort_gate` was created to end, and its failure
 * direction here is telling a placed student they are not placed.
 */
export function SessionsTab({
  sessions,
  unplacedReason = null,
}: {
  sessions: ClassSession[];
  unplacedReason?: string | null;
}) {
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
    // ⚠️ الترتيبُ مقصود: السببُ المعروفُ أوّلاً. القائمةُ فارغةٌ في الحالتَين،
    // والجملةُ الافتراضيّةُ تحتَها تتّهمُ مدرّساً جدولَ الحصصَ فعلاً.
    return unplacedReason !== null ? (
      <EmptyState
        title="حصص هذه المادّة تظهر بعد إسنادك إلى مجموعة"
        description={unplacedReason}
      />
    ) : (
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
              /* ٠٣٥ · T048 — صفحةُ الحصّةِ لا الغرفة: هذا التبويبُ يعرضُ
                 الماضيَ كذلك، والماضي لا غرفةَ له وله محتوىً يُفتَح. */
              href={`/sessions/${session.uuid}`}
              time="full"
              action={past ? <RecordingLink session={session} /> : <BookButton session={session} />}
            />
          </div>
        ))}
      </div>
    </section>
  );
}

type HeldBooking = NonNullable<ClassSession["my_booking"]>;

/**
 * «احجز» — the student's own door to a seat, and «إلغاء الحجز» its way back out.
 *
 * ⛔ SPEC 036 DECIDED «صفرُ حجزٍ آليّ — الباقةُ تُعطي رصيداً، والطالبُ يحجزُ بالمسارِ
 * العاديّ», and `POST /class-sessions/{uuid}/book` had no caller anywhere in the
 * frontend. So a student who bought a session package held credit with no way to
 * spend it. The server asks every eligibility question; this only offers the door.
 *
 * ⚠️ The refusal is the server's own sentence (`code: booking_refused`), never
 * the generic 409 one — «اكتملت المقاعد» and «رصيدك محجوز» are different next steps.
 */
function BookButton({ session }: { session: ClassSession }) {
  const [booking, setBooking] = useState<HeldBooking | null>(session.my_booking ?? null);
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);

  if (booking !== null) {
    /*
     | ⚠️ A CANCELLED BOOKING IS NOT «محجوز». The badge used to read the mere
     | presence of `my_booking`, so a seat given up — or taken back by the
     | system — kept telling the student they held it. The server's own label
     | says which of the three it was.
     */
    if (booking.status !== "booked") {
      return <Badge tone="neutral">{booking.status_label}</Badge>;
    }

    return (
      <div className="flex flex-wrap items-start justify-end gap-2">
        <Badge tone="success">محجوز</Badge>
        {session.status === "scheduled" && booking.may_cancel_until && (
          <CancelBookingButton
            bookingUuid={booking.uuid}
            mayCancelUntil={booking.may_cancel_until}
            timezone={session.timezone}
            onCancelled={(result) => setBooking({ ...booking, ...result })}
          />
        )}
      </div>
    );
  }

  if (session.status !== "scheduled" || session.seats.available <= 0) return null;

  const book = async () => {
    setBusy(true);
    setRefusal(null);

    try {
      const booked = await classSessions.book(session.uuid);
      // A seat booked a second ago is cancellable too — the deadline arrives
      // with the booking, since the nested session carries no `my_booking`.
      setBooking({
        uuid: booked.uuid,
        status: "booked",
        status_label: "محجوز",
        may_cancel_until: booked.may_cancel_until ?? "",
      });
    } catch (err) {
      setRefusal(
        err instanceof ApiError && errorCode(err.body) === "booking_refused" ? err.message : userMessage(err),
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col items-end gap-1">
      <Button size="sm" onClick={() => void book()} loading={busy} loadingLabel="جارٍ الحجز…">
        احجز
      </Button>
      {refusal !== null && (
        <p role="alert" className="text-xs text-danger-ink">
          {refusal}
        </p>
      )}
    </div>
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
