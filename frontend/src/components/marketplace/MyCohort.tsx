"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";

import { Button } from "@/components/ui/Button";
import { hasAuthToken } from "@/lib/api";
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

export function MyCohortProvider({
  courseUuid,
  children,
}: {
  courseUuid: string;
  children: ReactNode;
}) {
  const [mine, setMine] = useState<string | null>(null);

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

    return () => {
      alive = false;
    };
  }, [courseUuid]);

  return <MyCohortContext.Provider value={mine}>{children}</MyCohortContext.Provider>;
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
