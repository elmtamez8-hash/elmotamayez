"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { classSessions } from "@/lib/class-sessions";
import { ApiError } from "@/lib/api";
import { errorCode, userMessage } from "@/lib/errors";
import { privateSessions } from "@/lib/private-sessions";
import type { AvailabilityItem } from "@/lib/public-api";

/**
 * «أريد حصّة خاصّة» — the student picks an hour the teacher already declared.
 *
 * ⚠️ THE DURATION IS READ AND NEVER SENT (FR-016أ). The teacher declares it on
 * the course; a length the browser chose would make «حصة» three different things
 * at once — what a credit buys, what the teacher is paid for, and how much of
 * their calendar goes. `privateSessions.request()` carries ONE field, and the
 * test asserts that.
 *
 * ⚠️ AND SOMEBODY WHO DID NOT BUY THE COURSE IS NOT SHOWN A BUTTON (FR-015أ).
 * A control that is pressed and then refused teaches the reader that the product
 * is broken; the answer they need — «سجّل في الكورس أوّلاً» — is the same one
 * either way, and it is worth more before the press than after it.
 */

const DAYS = [
  "الأحد",
  "الإثنين",
  "الثلاثاء",
  "الأربعاء",
  "الخميس",
  "الجمعة",
  "السبت",
];

type Enrolment = "checking" | "enrolled" | "not-enrolled" | "signed-out";

/**
 * Every start this student could ask for: each declared window, cut into slots
 * that FIT — the whole duration, never merely its first minute (FR-016ب).
 *
 * Derived here from the same two facts the server checks against, and the server
 * checks again: this is what stops the reader being offered an hour that will be
 * refused, not what decides whether it is allowed.
 *
 * ⚠️ BUILT IN UTC — `getUTCDay` AND `setUTCHours`, NEVER THE LOCAL PAIR.
 * `availability_slots` stores `day_of_week`, `start_time` and `end_time` in UTC,
 * and `RequestPrivateSession` compares `starts_at->utc()->format('H:i:s')`
 * against those columns. Built with `setHours()` on a browser at UTC+3, a
 * 14:00–18:00 window renders as slots at local 14:00 and submits them as 11:00Z
 * — so the form OFFERS five hours and the server refuses four of them with «هذا
 * الوقت خارج مواعيد المدرّس المعلَنة», which is the pressed-then-refused shape
 * FR-015أ exists to forbid.
 *
 * ⚠️ AND ONLY A UTC-EXPLICIT ASSERTION CAN SEE IT. A test that compares
 * `getHours()` against the window's own numbers reads both sides in local time,
 * so it agrees with itself on every machine — including a broken build. The
 * guard is `toISOString()`.
 *
 * The DISPLAY below stays local, and that is correct: a window declared at 14:00
 * UTC is 17:00 to a student in Qatar, and that is the hour they turn up.
 */
export function startsWithin(
  windows: AvailabilityItem[],
  minutes: number,
  from: Date,
  weeks = 2,
): Date[] {
  const out: Date[] = [];

  for (let day = 0; day < weeks * 7; day += 1) {
    const date = new Date(from);
    date.setUTCDate(date.getUTCDate() + day);

    for (const window of windows) {
      if (date.getUTCDay() !== window.day_of_week) continue;

      const [openHour, openMinute] = window.start_time.split(":").map(Number);
      const [closeHour, closeMinute] = window.end_time.split(":").map(Number);

      const close = new Date(date);
      close.setUTCHours(closeHour, closeMinute, 0, 0);

      const slot = new Date(date);
      slot.setUTCHours(openHour, openMinute, 0, 0);

      while (slot.getTime() + minutes * 60_000 <= close.getTime()) {
        if (slot.getTime() > from.getTime()) out.push(new Date(slot));
        slot.setUTCMinutes(slot.getUTCMinutes() + minutes);
      }
    }
  }

  return out.sort((a, b) => a.getTime() - b.getTime());
}

export function PrivateSessionRequestForm({
  courseUuid,
  availability,
  minutes,
}: {
  courseUuid: string;
  availability: AvailabilityItem[];
  /** Null on the course means the platform default — never «no private sessions». */
  minutes: number | null;
}) {
  const [enrolment, setEnrolment] = useState<Enrolment>("checking");
  const [chosen, setChosen] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const length = minutes ?? 60;

  useEffect(() => {
    /*
     | ⚠️ READ HERE RATHER THAN TAKEN AS A PROP. The course page is a PUBLIC,
     | server-rendered route: the server does not know who is reading it — the
     | token lives in `localStorage` — so a `signedIn` prop could only ever be
     | `false`, and a signed-in student would be told to sign in.
     */
    if (localStorage.getItem("auth_token") === null) {
      setEnrolment("signed-out");

      return;
    }

    /*
     | The enrolment oracle. `/courses/{uuid}/next-session` answers `403` with
     | `code: not_enrolled` and is small — a purpose-built endpoint for one
     | boolean would be a sixth route answering a question this one already
     | answers, and a second spelling of «is this course yours».
     */
    classSessions
      .nextForCourse(courseUuid)
      .then(() => setEnrolment("enrolled"))
      .catch((err: unknown) =>
        /*
         | ⚠️ `errorCode` READS THE BODY, NOT THE ERROR. Passed the ApiError it
         | finds no `code` and answers null, so every refusal would read as
         | «enrolled» — and the unenrolled student would be shown a button that
         | is refused the moment they press it, which is the whole thing FR-015أ
         | forbids.
         |
         | ⚠️ AND ANYTHING ELSE FAILS OPEN. A network blip is not «you did not buy
         | this course»: telling a paying student to enrol again is worse than
         | letting them press a button that may refuse.
         */
        setEnrolment(
          err instanceof ApiError && errorCode(err.body) === "not_enrolled"
            ? "not-enrolled"
            : "enrolled",
        ),
      );
  }, [courseUuid]);

  const slots = startsWithin(availability, length, new Date());

  if (enrolment === "signed-out" || enrolment === "not-enrolled") {
    return (
      <Alert tone="info" title="الحصص الخاصة لطلاب الكورس">
        {enrolment === "signed-out" ? (
          <>
            <Link href="/login" className="font-bold underline">
              سجّل الدخول
            </Link>{" "}
            ثم اشترك في الكورس لتطلب حصة خاصة مع المدرّس.
          </>
        ) : (
          "اشترك في هذا الكورس أوّلاً، ثم يمكنك طلب حصة خاصة من مواعيد المدرّس المعلَنة."
        )}
      </Alert>
    );
  }

  if (enrolment === "checking") {
    return <p className="text-sm text-ink-muted">جارٍ التحقّق…</p>;
  }

  if (slots.length === 0) {
    // Never an empty gap: a section with nothing in it reads as a broken page
    // rather than as an answer.
    return (
      <Alert tone="info" title="لا مواعيد معلَنة">
        لم يعلن المدرّس مواعيد تتّسع لحصة بطول {length} دقيقة. تابع صفحته لتعرف حين
        يفتح موعداً.
      </Alert>
    );
  }

  if (sent) {
    return (
      <Alert tone="success" title="وصل طلبك">
        سيصل ردّ المدرّس إلى إشعاراتك. لم يُخصم من رصيدك شيء حتى الآن — الخصم عند
        القبول.
      </Alert>
    );
  }

  const submit = () => {
    if (chosen === null) return;

    setSending(true);
    setError(null);

    privateSessions
      .request(courseUuid, chosen)
      .then(() => setSent(true))
      // Never a raw error: the server's sentence names what to do next — the
      // hour is outside the declared window, the balance is short, three
      // requests are already waiting.
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setSending(false));
  };

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm text-ink-muted">
        مدة الحصة الخاصة في هذا الكورس {length} دقيقة، يحدّدها المدرّس. اختر موعداً
        من مواعيده المعلَنة.
      </p>

      {error && <Alert tone="danger" title="لم يُرسل الطلب">{error}</Alert>}

      <ul className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2">
        {slots.slice(0, 24).map((slot) => {
          const value = slot.toISOString();

          return (
            <li key={value}>
              <button
                type="button"
                onClick={() => setChosen(value)}
                aria-pressed={chosen === value}
                className={`w-full rounded-xl border px-3 py-2 text-start text-xs font-medium transition ${
                  chosen === value
                    ? "border-primary bg-primary-soft text-primary-ink"
                    : "border-line text-ink hover:border-primary"
                }`}
              >
                {DAYS[slot.getDay()]}{" "}
                {slot.toLocaleDateString("ar-QA", { day: "numeric", month: "long" })}
                {" · "}
                {slot.toLocaleTimeString("ar-QA", {
                  hour: "2-digit",
                  minute: "2-digit",
                })}
              </button>
            </li>
          );
        })}
      </ul>

      <Button onClick={submit} disabled={chosen === null || sending}>
        {sending ? "جارٍ الإرسال…" : "أرسل الطلب"}
      </Button>
    </div>
  );
}
