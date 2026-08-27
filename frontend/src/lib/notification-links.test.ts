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
  { path: "/manage/bank/import/i1j2k3l4", from: "NotifyImportReady" },
  { path: "/certificates/verify/ABC123", from: "NotifyStudentCertificateIssued · …Regenerated" },
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
    "/courses/c1b2a3d4",
    "/exams/attempts/a1b2c3d4",
    "/assignments/a1b2c3d4",
    "/manage/assignments/a1b2c3d4",
    "/teacher/application",
  ])("%s is still not a route, which is why it was a 404", (path) => {
    expect(known.some((route) => matches(route, path))).toBe(false);
  });
});
