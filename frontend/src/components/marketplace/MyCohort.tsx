"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";

import { Button } from "@/components/ui/Button";
import { auth, hasAuthToken } from "@/lib/api";
import { cohorts } from "@/lib/cohorts";
import { TONE_CLASSES } from "@/lib/labels";

/**
 * «مجموعتك» — marking, on a PUBLIC course page, the one group this reader is in.
 *
 * ⚠️ THE MEMBERSHIP CANNOT COME FROM THE PAGE'S OWN PAYLOAD, and that is the
 * whole design here. `/courses/{slug}` is rendered on the SERVER for a visitor
 * who is usually not signed in at all — it is crawlable, and its payload is
 * guarded by `PublicFieldAllowlist`. Putting «is this reader a member» into it
 * would make a cacheable public document vary per person, which is the shape of
 * a page that eventually shows one student another student's state.
 *
 * So the answer arrives on the CLIENT, after the page has rendered, from the
 * authenticated route that already knows it (`GET /courses/{uuid}/cohorts` →
 * `membership`). A guest never asks.
 *
 * ⚠️ AND IT IS ONE REQUEST FOR THE WHOLE LIST, NOT ONE PER CARD. The provider
 * wraps the SERVER-rendered `CohortList` — children passed through a client
 * component are still server-rendered, so nothing is lost to SEO — and each card
 * carries only a tiny client leaf that reads the context. A badge that fetched
 * for itself would issue an identical request per group, which is the N+1 this
 * repository keeps paying for, wearing a browser's clothes.
 */
const MyCohortContext = createContext<string | null>(null);

/**
 * Whether the reader is a teacher-side account — a teacher or an assistant.
 *
 * ⛔ REPORTED 2026-09-08: the public page of a course offered «اشترك في هذه
 * المجموعة» to the course's own owner, and the three purchase doors accepted it.
 * The doors refuse now; this is the other half, because a control that is
 * offered and then refused is a promise the product does not keep.
 *
 * ⚠️ AND IT IS NOT A GUARD. The guard is on the server, on all three doors, with
 * its own tests. This hides a button — nothing more — which is exactly the
 * division `UnlessMyCohort` below already draws for the reader's own group.
 *
 * ⚠️ READ FROM `/auth/me`'s `workspaces`, WHICH IS ALREADY THE RIGHT QUESTION.
 * `UserResource::workplaces()` answers «the places this person works — owned or
 * assisted at» and returns an empty list for a student or a guardian outright,
 * so a non-empty one IS a teacher-side account. Adding a `viewer_teaches` field
 * would be a second answer to a question the payload already answers, and a
 * second answer is what drifts.
 */
const ViewerTeachesContext = createContext(false);

export function MyCohortProvider({
  courseUuid,
  children,
}: {
  courseUuid: string;
  children: ReactNode;
}) {
  const [mine, setMine] = useState<string | null>(null);
  const [teaches, setTeaches] = useState(false);

  useEffect(() => {
    /*
      ⚠️ `hasAuthToken()` AND NOT THE AUTH CONTEXT. Public pages are not wrapped
      in `AuthProvider` — its own docblock says so — and reaching for it here
      would put an `/auth/me` request on every crawlable page in the product.
    */
    if (!hasAuthToken()) return;

    let alive = true;

    cohorts
      .forCourse(courseUuid)
      .then((res) => {
        if (alive) setMine(res.membership?.cohort_uuid ?? null);
      })
      /*
        Swallowed on purpose, and this is one of the few places that is right: a
        reader who is signed in but not enrolled is refused by that route, and
        «you are not in any group here» is exactly what the page already shows by
        marking none of them. There is nothing to tell them and nothing to act on.
      */
      .catch(() => undefined);

    /*
      ⚠️ A SECOND REQUEST, AND ONLY FOR A SIGNED-IN READER. `hasAuthToken()`
      already gates the block, so a crawler and a logged-out visitor still make
      zero requests from this page — which is the whole reason that guard is
      above rather than inside each fetch.

      Swallowed for the same reason the one above is: if we cannot tell whether
      the reader teaches, showing the button is the status quo, and the server
      refuses it anyway.
    */
    auth
      .me()
      .then((user) => {
        if (alive) setTeaches(user.workspaces.length > 0);
      })
      .catch(() => undefined);

    return () => {
      alive = false;
    };
  }, [courseUuid]);

  return (
    <MyCohortContext.Provider value={mine}>
      <ViewerTeachesContext.Provider value={teaches}>{children}</ViewerTeachesContext.Provider>
    </MyCohortContext.Provider>
  );
}

/** Marks the reader's own group. Renders nothing for everyone else's. */
export function MyCohortBadge({ cohortUuid }: { cohortUuid: string }) {
  const mine = useContext(MyCohortContext);

  if (mine !== cohortUuid) return null;

  return (
    <span
      // ⚠️ A tone from `TONE_CLASSES`, never a colour class of its own: there is
      // no `bg-info` in `@theme`, and Tailwind emits no rule for a token it has
      // never seen — the badge would be invisible with no error anywhere, which
      // this tree has shipped four times.
      className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${TONE_CLASSES.info}`}
    >
      مجموعتك
    </span>
  );
}

/**
 * ⚠️ THE SUBSCRIBE BUTTON MUST NOT STAND ON THE READER'S OWN GROUP. Without this
 * the card says «مجموعتك» and offers «اشترك في هذه المجموعة» in the same breath —
 * an invitation to buy a place they already hold, which the server would refuse
 * after taking them through a payment screen.
 *
 * It hides rather than disables, the same rule the list already follows for a
 * full or closed group: a dead control is a promise the product will not keep.
 */
export function UnlessMyCohort({
  cohortUuid,
  children,
}: {
  cohortUuid: string;
  children: ReactNode;
}) {
  const mine = useContext(MyCohortContext);
  const teaches = useContext(ViewerTeachesContext);

  // ⛔ A teacher never buys — in any course, theirs included. Same reasoning as
  // the reader's own group one line down: the server refuses it, so offering it
  // is a payment screen that ends in a refusal.
  if (teaches) return null;

  if (mine === cohortUuid) return null;

  return <>{children}</>;
}

/**
 * The way IN to the group this reader already belongs to.
 *
 * ⚠️ A PUBLIC PAGE THAT ONLY SELLS IS A DEAD END FOR THE PERSON WHO ALREADY
 * BOUGHT. A student who opens their course's public address — from a search
 * result, a shared link, their own bookmark — was reading «اشترك في هذه
 * المجموعة» about a group they are sitting in, with nothing anywhere on the page
 * leading to it. The badge names their group; this opens it.
 *
 * ⚠️ AND IT IS DRAWN OUTSIDE THE `is_joinable` BRANCH, deliberately: a member
 * stays a member of a group that has since filled up or closed, and hiding their
 * own door because the group stopped taking newcomers is the same class of
 * mistake as showing them a subscribe button.
 *
 * The destination is `?tab=roster` — «الزملاء», the group itself. The tab strip
 * reads that parameter from the address, so the link lands on the group rather
 * than on the curriculum with the group one more click away.
 */
export function MyCohortLink({
  courseUuid,
  cohortUuid,
}: {
  courseUuid: string;
  cohortUuid: string;
}) {
  const mine = useContext(MyCohortContext);

  if (mine !== cohortUuid) return null;

  return (
    <Button
      href={`/enrollments/${encodeURIComponent(courseUuid)}?tab=roster`}
      variant="secondary"
      size="sm"
      fullWidth
    >
      افتح مجموعتك
    </Button>
  );
}
