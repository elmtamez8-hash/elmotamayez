"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { classSessions } from "@/lib/class-sessions";
import { ApiError, auth } from "@/lib/api";
import { teachesOnPlatform } from "@/lib/teaches-on-platform";
import { errorCode, userMessage } from "@/lib/errors";
import { privateSessions } from "@/lib/private-sessions";
import type { AvailabilityItem } from "@/lib/public-api";
import { startsWithin } from "@/lib/availability";
import { formatSessionClock } from "@/lib/session-format";
import { weekdayIn } from "@/lib/timezone";
import { counted, NOUNS, timezoneLabel } from "@/lib/labels";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";

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

/*
 | ⚠️ `"teaches"` IS A FIFTH STATE AND NOT A FLAG BESIDE THE OTHER FOUR. A
 | teacher-side account holds no enrolment anywhere, so without it they land in
 | `"not-enrolled"` and are shown «اشترك بحصص خاصة» about their own course — the
 | exact defect reported on 2026-09-08 for the group cards, arriving through the
 | second door on the same page.
 */
type Enrolment = "checking" | "enrolled" | "not-enrolled" | "signed-out" | "teaches";

/*
 | Every start this student could ask for lives in `lib/availability`
 | (`startsWithin`): each declared window, cut into slots that FIT, built PER
 | DATE in the window's own zone (2026-09-25). The server checks the ask the same
 | way (`AvailabilitySlot::containsSpan()`), so an hour offered here is accepted
 | there on both sides of a DST change.
 |
 | ⚠️ AND THE BUTTONS ARE DRAWN ON THE VIEWER'S CLOCK, WITH THE CLOCK NAMED. They
 | used to be drawn on the browser's clock while «حصصي» drew the same lesson on
 | Doha's — so from 2026-10-29 a Cairo student picked 17:00 here and saw 18:00
 | there. Both now read `useViewerTimeZone()`, and the teacher may be in another
 | country, so the zone is printed beside the hours.
 */

export function PrivateSessionRequestForm({
  courseUuid,
  availability,
  minutes,
  leadMinutes,
  subscriptionAvailable,
}: {
  courseUuid: string;
  availability: AvailabilityItem[];
  /**
   * Whether the teacher actually has a priced one-to-one plan (027 · FR-003).
   *
   * ⚠️ THE SERVER DECIDES IT. Drawing the invitation without asking produces a
   * button that is pressed and answered «هذه الباقة غير متاحة» — the same
   * pressed-then-refused shape this component's own refusal branch was written
   * to avoid, arriving through the door meant to fix it.
   */
  subscriptionAvailable: boolean;
  /** Null on the course means the platform default — never «no private sessions». */
  minutes: number | null;
  /**
   * How far ahead an hour may be asked for, in minutes (the server's own number).
   *
   * ⚠️ THE PICKER COUNTS FROM IT. Starting at `now` offered the next slot even
   * when it began in ten minutes, and `RequestPrivateSession` refuses that as
   * too soon — the pressed-then-refused shape FR-015أ forbids.
   */
  leadMinutes: number;
}) {
  const zone = useViewerTimeZone();
  const [enrolment, setEnrolment] = useState<Enrolment>("checking");
  const [teaches, setTeaches] = useState(false);
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
    /*
     | ⛔ ASKED BEFORE THE ENROLMENT ORACLE, because for a teacher that oracle
     | answers `not_enrolled` — truthfully, and about the wrong question. The
     | reader is not a student who has yet to buy; they are the person selling.
     |
     | `workspaces` from `/auth/me` IS the question: `UserResource::workplaces()`
     | answers «the places this person works — owned or assisted at» and returns
     | an empty list for a student or a guardian outright. No new field, and the
     | same predicate `MyCohortProvider` reads for the group cards — one spelling
     | for one question, on both of this page's subscribe doors.
     */
    void auth
      .me()
      .then((user) => {
        if (teachesOnPlatform(user)) setTeaches(true);
      })
      // A reader we cannot classify is treated as a student: the server refuses
      // a teacher at the door anyway, so the cost of guessing wrong here is one
      // Arabic sentence, while the opposite default hides the buy button from
      // every student whose request happened to time out.
      .catch(() => undefined);

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

  const slots = startsWithin(
    availability,
    length,
    new Date(Date.now() + leadMinutes * 60_000),
  );

  /*
   * ⚠️ THIS BRANCH USED TO BE A CLOSED DOOR, AND IT WAS THE ONLY DOOR (027 · FR-003).
   *
   * «اشترك في هذا الكورس أوّلاً» was a correct sentence with nowhere to go: the
   * visitor looked for a way to subscribe, found none on the page, and the
   * credits screen sent them back to the catalogue they had come from. It is an
   * invitation now — and it is the SAME screen for the signed-out visitor,
   * because `/subscribe` is behind the app shell and carries them through login
   * and back with their choice intact.
   *
   * The invitation is drawn only when there is something to buy: without a
   * priced one-to-one plan the press is answered «هذه الباقة غير متاحة», which
   * is the pressed-then-refused shape this very branch exists to avoid.
   */
  /*
    ⛔ A TEACHER IS NEVER INVITED TO BUY — in any course, theirs included. The
    server refuses all three purchase doors (`TeacherNeverBuysTest`); this is the
    other half, because a control offered and then refused is a payment screen
    that ends in a sentence.

    It says something rather than rendering null: the heading «حصة خاصة» is drawn
    by the page above this component, and an empty panel under a heading reads as
    a screen that failed to load.
  */
  if (teaches) {
    return (
      <Alert tone="info" title="الحصص الخاصة">
        هذه الشاشة لطلابك. الحصص الخاصة تُطلب باشتراكٍ من حساب الطالب، ومواعيدك
        المعلنة هي ما يختارون منه.
      </Alert>
    );
  }

  if (enrolment === "signed-out" || enrolment === "not-enrolled") {
    if (!subscriptionAvailable) {
      return (
        <Alert tone="info" title="الحصص الخاصة لطلاب الكورس">
          لم يفتح المدرّس اشتراكاً بحصص خاصة في هذا الكورس بعد. تابع صفحته لتعرف حين
          يفتحه.
        </Alert>
      );
    }

    return (
      <Alert tone="info" title="اشترك بحصص خاصة">
        <p>
          الحصص الخاصة تُطلب باشتراك بالمدّة مع المدرّس. تختار الباقة وترفع إيصال
          التحويل في شاشة واحدة، ويبدأ اشتراكك عند اعتماد الدفعة — لا قبله.
        </p>
        <p className="mt-3">
          <Link
            href={`/subscribe?course=${encodeURIComponent(courseUuid)}&mode=private`}
            className="font-bold underline"
          >
            اشترك بحصص خاصة
          </Link>
        </p>
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
        لم يعلن المدرّس مواعيد تتّسع لحصة بطول {counted(length, { ...NOUNS.minutes, two: "دقيقتين" })}. تابع صفحته لتعرف حين
        يفتح موعداً.
      </Alert>
    );
  }

  if (sent) {
    return (
      <Alert tone="success" title="وصل طلبك">
        <p>
          سيصل ردّ المدرّس إلى إشعاراتك. لم يُخصم من رصيدك شيء حتى الآن — الخصم عند
          القبول.
        </p>
        <p className="mt-3">
          <Link href="/private-sessions" className="font-bold underline">
            تابع طلباتك في «حصصي الخاصة»
          </Link>
        </p>
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
        مدة الحصة الخاصة في هذا الكورس {counted(length, NOUNS.minutes)}، يحدّدها المدرّس. اختر موعداً
        من مواعيده المعلَنة. المواعيد بـ<bdi>{timezoneLabel(zone)}</bdi>.
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
                {DAYS[weekdayIn(slot, zone)]}{" "}
                {slot.toLocaleDateString("ar", { day: "numeric", month: "long", timeZone: zone })}
                {" · "}
                {formatSessionClock(value, zone)}
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
