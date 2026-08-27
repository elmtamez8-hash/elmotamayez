"use client";

import { SessionChat } from "@/components/community/SessionChat";

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
export function ChatTab({ cohortUuid }: { cohortUuid: string | null }) {
  /*
    A course with no group of the reader's own has no thread to show — and the
    course may have no groups at all (FR-036), which is the ordinary case for
    every course that shipped before this spec.
  */
  if (cohortUuid === null) return null;

  return <SessionChat kind="cohort" uuid={cohortUuid} title="نقاش المجموعة" />;
}
