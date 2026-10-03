/**
 * A quick poll in the live class (owner decisions 2026-10-03): the teacher writes
 * a question with 2–6 choices, each student picks one, the teacher sees who
 * picked what as it happens, and the class sees the result once the teacher
 * closes it. It lives for the lesson only — nothing is stored on our server.
 *
 * ⚠️ TWO CHANNELS, ONE PER DIRECTION, and the choice of each is the security:
 * - The poll travels as a DATA MESSAGE from the host. A student's ticket carries
 *   `canPublishData = false`, so a data message can only have come from a host:
 *   no student can push a fake poll to the class.
 * - A vote is the student's own PARTICIPANT ATTRIBUTE `poll` = `<id>:<choice>`,
 *   like the raised hand. Its identity is set by the provider from the signed
 *   ticket, so nobody votes in another's name, and the provider replays it to a
 *   teacher who reloads. It is CLIENT-WRITTEN, so it is parsed strictly and never
 *   printed: only a choice number inside the current poll counts.
 */

export const POLL_TOPIC = "poll";
export const POLL_ATTRIBUTE = "poll";
export const MIN_OPTIONS = 2;
export const MAX_OPTIONS = 6;
export const MAX_QUESTION = 200;
export const MAX_OPTION = 80;

export type PollState = "open" | "closed" | "ended";

export interface Poll {
  v: 1;
  id: string;
  question: string;
  options: string[];
  /** open: voting · closed: the result is shown to the class · ended: hidden. */
  state: PollState;
  /** The count per choice — sent with the result only, never while voting. */
  counts?: number[];
}

/** Ready-made choices, so a question is one tap away. */
export const POLL_PRESETS: { label: string; question: string; options: string[] }[] = [
  { label: "أ / ب / ج / د", question: "", options: ["أ", "ب", "ج", "د"] },
  { label: "صح / غلط", question: "", options: ["صح", "غلط"] },
  { label: "فهمت؟", question: "فهمت؟", options: ["أيوه", "لأ"] },
];

export function newPollId(): string {
  return Math.random().toString(36).slice(2, 10).padEnd(8, "0");
}

export function encodePoll(poll: Poll): Uint8Array<ArrayBuffer> {
  return new TextEncoder().encode(JSON.stringify(poll));
}

const isText = (value: unknown, max: number): value is string => typeof value === "string" && value.length <= max;

/** A poll from the data channel, or null for anything that is not exactly one. */
export function decodePoll(payload: Uint8Array): Poll | null {
  let raw: unknown;
  try {
    raw = JSON.parse(new TextDecoder().decode(payload));
  } catch {
    return null;
  }
  if (typeof raw !== "object" || raw === null) return null;
  const p = raw as Record<string, unknown>;
  if (p.v !== 1 || typeof p.id !== "string" || !/^[a-z0-9]{8}$/.test(p.id)) return null;
  if (!isText(p.question, MAX_QUESTION) || !Array.isArray(p.options)) return null;
  if (p.options.length < MIN_OPTIONS || p.options.length > MAX_OPTIONS) return null;
  if (!p.options.every((option) => isText(option, MAX_OPTION) && option.trim() !== "")) return null;
  if (p.state !== "open" && p.state !== "closed" && p.state !== "ended") return null;
  let counts: number[] | undefined;
  if (p.counts !== undefined) {
    if (!Array.isArray(p.counts) || p.counts.length !== p.options.length) return null;
    if (!p.counts.every((n) => Number.isInteger(n) && (n as number) >= 0)) return null;
    counts = p.counts as number[];
  }

  return { v: 1, id: p.id, question: p.question, options: p.options as string[], state: p.state, counts };
}

/** The attribute a student writes for a choice. */
export function voteValue(pollId: string, choice: number): string {
  return `${pollId}:${choice}`;
}

/** The choice an attribute names IN THIS POLL, or null (another poll, garbage, none). */
export function readVote(value: string | undefined, poll: Pick<Poll, "id" | "options">): number | null {
  const match = /^([a-z0-9]{8}):(\d)$/.exec(value ?? "");
  if (!match || match[1] !== poll.id) return null;
  const choice = Number(match[2]);

  return choice < poll.options.length ? choice : null;
}

/** Who chose what, from each participant's attribute: the voters per choice, in arrival order. */
export function tally(poll: Pick<Poll, "id" | "options">, votes: Iterable<[identity: string, value: string | undefined]>): string[][] {
  const voters: string[][] = poll.options.map(() => []);
  for (const [identity, value] of votes) {
    const choice = readVote(value, poll);
    if (choice !== null) voters[choice].push(identity);
  }

  return voters;
}

/** Whole percentages of a total, 0 when nobody voted. */
export function percent(count: number, total: number): number {
  return total === 0 ? 0 : Math.round((count / total) * 100);
}
