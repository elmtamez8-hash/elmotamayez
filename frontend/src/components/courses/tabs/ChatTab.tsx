"use client";

import { useState } from "react";

import { SessionChat } from "@/components/community/SessionChat";
import { SelectField } from "@/components/ui/Field";

/**
 * The group's thread inside the course page (021 · FR-046).
 *
 * ⚠️ IT IS A WRAPPER AND NOT A SECOND CHAT. `SessionChat` already resolves the
 * room, merges over the socket, falls back to polling when the socket will not
 * open, and carries the lock and the moderation controls — a copy of it for a
 * third kind would be two implementations of «what a room does», and the one
 * nobody exercised would be the one that stops merging.
 *
 * ⚠️ AND IT RENDERS NOTHING FOR SOMEBODY WHO WAS NEVER IN THE GROUP. The child
 * answers a 403 with silence rather than a banner: telling a student there is a
 * conversation they may not read advertises a room and refuses it in one breath.
 */
export function ChatTab({
  cohortUuid,
  pastCohorts = [],
}: {
  cohortUuid: string | null;
  /** Groups this reader has left. Read-only for ever — see the picker below. */
  pastCohorts?: Array<{ uuid: string; name: string }>;
}) {
  /*
    ⚠️ THE ARCHIVE HAD NO DOOR, AND THAT WAS THE WHOLE GAP. FR-046 keeps an old
    group's thread readable for ever, `ConversationPolicy::view()` admits whoever
    was EVER a member, and `post()` refuses them with a sentence — all of it
    shipped in US4 and reachable from nowhere, because this component only ever
    knew the group the reader is in TODAY. A permission nothing links to is a
    permission nobody has.

    A `<select>` rather than a second tab: an archive is not a peer of the live
    thread, and a course with three past groups would otherwise grow three tabs
    carrying the same word.
  */
  const [selected, setSelected] = useState<string | null>(null);

  /*
    A course with no group of the reader's own has no thread to show — and the
    course may have no groups at all (FR-036), which is the ordinary case for
    every course that shipped before this spec.
  */
  if (cohortUuid === null) return null;

  const active = selected ?? cohortUuid;
  const past = pastCohorts.find((cohort) => cohort.uuid === active) ?? null;

  return (
    <div className="space-y-3">
      {pastCohorts.length > 0 && (
        <div className="max-w-xs">
          <SelectField
            id="cohort-thread"
            label="النقاش"
            value={active}
            onChange={setSelected}
            options={[
              { value: cohortUuid, label: "مجموعتي الحاليّة" },
              ...pastCohorts.map((cohort) => ({
                value: cohort.uuid,
                // Said in the option itself: a reader who picks it and only then
                // finds the composer refusing them has been told at the wrong
                // end of the action.
                label: `${cohort.name} — سابقة (قراءة فقط)`,
              })),
            ]}
          />
        </div>
      )}

      {/*
        `key` on the uuid so switching threads REMOUNTS the room. Without it the
        child keeps the messages, the socket subscription and the composer state
        of the previous conversation while fetching the new one — the reader sees
        the old group's messages under the new group's name for a beat.
      */}
      <SessionChat
        key={active}
        kind="cohort"
        uuid={active}
        title={past === null ? "نقاش المجموعة" : `نقاش ${past.name}`}
      />
    </div>
  );
}
