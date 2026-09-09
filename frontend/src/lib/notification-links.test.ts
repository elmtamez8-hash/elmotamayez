import { readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

/*
| ⚠️ EVERY PLACE A NOTIFICATION CAN SEND SOMEBODY MUST BE A PLACE THAT EXISTS.
|
| `notifications.action_url` is the whole point of a notification centre and the
| only control on most of its rows — and SEVEN of them pointed at routes this
| application has never had:
|
|   /sessions/{uuid}            there is only /sessions/[uuid]/room
|   /courses/{uuid}             the catalogue has no detail route
|   /exams/attempts/{uuid} ×2   the result screen is /exams/[uuid]/result
|   /assignments/{uuid}         handing in happens on the list
|   /manage/assignments/{uuid}  likewise
|   /teacher/application        never existed under any name
|
| Nothing caught them. The backend cannot see this route tree, so its tests pass
| on any string; and the pages themselves render perfectly — the 404 only happens
| to whoever presses. It was found by a person clicking one row, after the
| notification centre's redesign turned the whole row into the target and made
| the dead link impossible to miss.
|
| ⚠️ THIS TEST READS THE REAL ROUTE TREE, NEVER A LIST OF ROUTES. A list would be
| a second copy that agrees with itself — the failure it has to catch is a route
| being renamed or removed while a link still names the old one, and a hand-kept
| list of routes would be renamed right along with it. What IS written down here
| is the set of destinations the API sends; adding a new `actionUrl:` in the
| backend means adding it below, and this comment is the only place that says so.
*/

const APP = join(process.cwd(), "src", "app");

/** Every route the app router serves, as a matchable pattern. */
function routes(dir: string, prefix = ""): string[] {
  const found: string[] = [];

  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);

    if (!statSync(full).isDirectory()) continue;

    // A route GROUP produces no URL segment — `(app)` and `(shell)` are why
    // `/notifications` and `/login` sit at the same depth in the tree and the
    // same depth in a URL.
    const segment = entry.startsWith("(") && entry.endsWith(")") ? prefix : `${prefix}/${entry}`;

    if (readdirSync(full).includes("page.tsx")) found.push(segment === "" ? "/" : segment);

    found.push(...routes(full, segment));
  }

  return found;
}

/** `/exams/[uuid]/result` matches `/exams/abc-123/result`. */
function matches(route: string, path: string): boolean {
  const r = route.split("/");
  const p = path.split("/");

  if (r.length !== p.length) return false;

  return r.every((part, i) => (part.startsWith("[") && part.endsWith("]") ? p[i] !== "" : part === p[i]));
}

/**
 * Every destination `notifications.action_url` can carry, with the backend file
 * that builds it. A dynamic segment is written with a real-looking value,
 * because that is what the reader's browser actually receives.
 */
const DESTINATIONS: Array<{ path: string; from: string }> = [
  { path: "/dashboard", from: "several listeners" },
  { path: "/schedule", from: "IngestSessionRecordingJob (seat holders)" },
  { path: "/billing", from: "Payments listeners" },
  { path: "/progress", from: "Gamification listeners" },
  { path: "/reviews", from: "NotifyPeriodicReviewPublished" },
  { path: "/shop", from: "NotifyRewardRedeemed" },
  { path: "/settings/privacy", from: "Compliance listeners" },
  { path: "/manage/settlement", from: "Settlement listeners" },
  { path: "/signup/teacher/submitted", from: "NotifyTeacherChangesRequested" },
  { path: "/assignments", from: "NotifyStudentSubmissionGraded" },
  { path: "/manage/assignments", from: "NotifyTeacherAssignmentSubmitted" },
  { path: "/enrollments/c1b2a3d4", from: "NotifyStudentEnrolled" },
  { path: "/exams/a1b2c3d4/result", from: "NotifyStudentExamResult · NotifyStudentGradingPending" },
  { path: "/messages/m1n2o3p4", from: "NotifyOfflineRecipient" },
  { path: "/manage/sessions/s1t2u3v4", from: "IngestSessionRecordingJob (teacher)" },
  // 023 · US3. The teacher's queue has a DEADLINE running on every row, so a
  // dead link here is a request that expires unanswered — and the student is
  // told «انتهت المهلة» about a lesson their teacher meant to give them.
  { path: "/manage/private-sessions", from: "NotifyTeacherPrivateSessionRequested" },
  { path: "/courses/c1b2a3d4", from: "NotifyStudentPrivateSessionDecided (rejected) · …Expired" },
  { path: "/manage/bank/import/i1j2k3l4", from: "NotifyImportReady" },
  { path: "/certificates/verify/ABC123", from: "NotifyStudentCertificateIssued · …Regenerated" },
  // 027 · FR-030. The ONLY notification in the product that links to a room
  // rather than to /schedule, and deliberately so: the requirement asks for «the
  // way in», not for a page the student has to search from. `/manage/sessions`
  // is the teacher's half of the seat-unavailable notice.
  { path: "/sessions/s1t2u3v4/room", from: "NotifySubscriptionActivated" },
  { path: "/manage/sessions", from: "NotifySubscriptionSeatUnavailable (teacher)" },
  /*
   * 030 · FR-006. Three entries and only two of them are new: the guardian's
   * consent notice has existed since 013 and pointed NOWHERE — dispatched with no
   * `actionUrl`, so `NotificationRow` rendered it as a plain <div>, telling a
   * guardian a child's account was waiting on them and giving them nothing to
   * press. It has a button on that page now.
   */
  { path: "/family", from: "LinkGuardian · AcceptRelation · RegisterStudent::inviteGuardian" },
];

describe("notification destinations", () => {
  const known = routes(APP);

  it("finds the route tree at all", () => {
    // The control: a glob that silently found nothing would pass every
    // assertion below by having no route to contradict.
    expect(known).toContain("/notifications");
    expect(known.length).toBeGreaterThan(50);
  });

  it.each(DESTINATIONS)("$path resolves to a page ($from)", ({ path }) => {
    expect(known.some((route) => matches(route, path))).toBe(true);
  });

  /*
   | ⚠️ AND THE SEVEN THAT WERE BROKEN STAY BROKEN, so a revert cannot pass.
   | Without this the fix above could be undone and every assertion would still
   | be green — each of the old paths is simply absent from the list, and a list
   | proves nothing about what is not in it.
  */
  it.each([
    "/sessions/s1t2u3v4",
    "/exams/attempts/a1b2c3d4",
    "/assignments/a1b2c3d4",
    "/manage/assignments/a1b2c3d4",
    "/teacher/application",
  ])("%s is still not a route, which is why it was a 404", (path) => {
    expect(known.some((route) => matches(route, path))).toBe(false);
  });

  /*
   | ⚠️ `/courses/{uuid}` LEFT THE LIST ABOVE ON 2026-09-02, AND ONLY BECAUSE
   | SPEC 023 BUILT IT.
   |
   | The premise of that list is «this address has never existed, so a link
   | naming it is a 404». For this one the premise stopped being true: the
   | course's own public page is now a route, so leaving the entry above would
   | have made a NEW FEATURE fail a test whose subject is dead links.
   |
   | It is not simply deleted, because deleting it loses the fact that a
   | notification once pointed here and should not: `NotifyStudentEnrolled` sends
   | an enrolled student to `/enrollments/{uuid}` — their own copy, with their
   | progress — and the marketplace page is the pre-purchase view of the same
   | course. Correct address, wrong reader. So the assertion inverts rather than
   | disappears.
  */
  it("has a public course page now, and the enrolment notice still does not use it", () => {
    expect(known.some((route) => matches(route, "/courses/c1b2a3d4"))).toBe(true);

    /*
     | ⚠️ NARROWED IN 023, AND THE NARROWING IS THE POINT. The address is
     | legitimate for some senders now: a REFUSED private-session request sends
     | the student back to the course page because that is where the teacher's
     | other declared hours are. What must never point here is the ENROLMENT
     | notice — a student who has bought the course belongs on their own copy of
     | it, with their progress, not on the pre-purchase view.
     |
     | So the assertion names the sender rather than the path. Left as «no
     | destination is /courses/{uuid}» it would have failed a correct new feature
     | over a fact about a different listener entirely.
     */
    const enrolment = DESTINATIONS.filter((d) => d.from.includes("NotifyStudentEnrolled"));

    expect(enrolment).not.toHaveLength(0);
    for (const destination of enrolment) {
      expect(destination.path).not.toBe("/courses/c1b2a3d4");
      expect(destination.path).toContain("/enrollments/");
    }
  });
});
