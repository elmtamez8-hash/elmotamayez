"use client";

import { useEffect, useState } from "react";

import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { cohorts as cohortsApi, type CohortMember } from "@/lib/cohorts";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";

/**
 * The classmates (021 · FR-050).
 *
 * ⚠️ A RANK-LESS MEMBER SHOWS NO NUMBER AT ALL (FR-051). The key is absent from
 * the payload for anyone the nightly roll-up has not seen, and the two guards
 * have to agree: rendering `rank ?? 0` here would put «المركز ٠» beside a
 * newcomer's name in front of their whole class, which is precisely what the
 * server took the trouble to omit.
 *
 * ⚠️ AND NOT ONE FIELD ABOUT ATTENDANCE, A STAY, A MARK OR A NOTE (FR-052). The
 * API does not send them; this file must not start asking for them either.
 */
export function RosterTab({ cohortUuid }: { cohortUuid: string | null }) {
  const [members, setMembers] = useState<CohortMember[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (cohortUuid === null) return;

    setError(null);

    cohortsApi
      .roster(cohortUuid)
      // ⚠️ NOT `.catch(() => [])`. An empty list says «مجموعتك فارغة» about a
      // refusal or an outage, and the rule against showing a raw error is not a
      // rule for inventing a reassuring one.
      .then((response) => setMembers(response.members ?? []))
      .catch((err: unknown) => setError(userMessage(err)));
  }, [cohortUuid]);

  if (cohortUuid === null) return null;
  if (error !== null) return <ErrorState description={error} />;
  if (members === null) return <RowsSkeleton />;

  if (members.length === 0) {
    return (
      <EmptyState
        title="لا زملاء بعد"
        description="أنت أوّل من انضمّ إلى هذه المجموعة."
      />
    );
  }

  return (
    <ul className="grid gap-3 sm:grid-cols-2">
      {members.map((member, index) => (
        <li
          key={member.uuid}
          className="banner-rise flex items-center gap-3 rounded-2xl border border-line bg-surface p-3"
          // Capped, and the fill mode lives in the class: `banner-rise` covers
          // the delay as well as the animation, so a card is not painted, hidden
          // when its turn comes and repainted.
          style={{ animationDelay: `${Math.min(index, 8) * 40}ms` }}
        >
          <Avatar url={member.avatar_url} name={member.name} />

          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-ink">{member.name}</p>

            <div className="mt-1 flex flex-wrap items-center gap-1.5">
              {member.level !== undefined && (
                <Badge tone="info">
                  المستوى <bdi>{arabicNumber(member.level)}</bdi>
                </Badge>
              )}

              {/* Absent, never zero — see the note above the component. */}
              {member.rank !== undefined && (
                <Badge tone="warning">
                  المركز <bdi>{arabicNumber(member.rank)}</bdi>
                </Badge>
              )}

              {/*
                An empty badge list is a STATE and draws nothing — never a dash
                and never a placeholder. A teacher, an assistant and a student on
                their first day all hold none.
              */}
              {member.badges.map((badge) => (
                <Badge key={badge.key} tone="neutral">
                  {badge.name}
                </Badge>
              ))}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}
