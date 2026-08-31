import { api } from "./api";
import type { AdaptiveDifficulty } from "./adaptive";

/**
 * Group study rooms (spec 012 · US3).
 *
 * ⚠️ NOTHING HERE COUNTS TOWARDS AN OFFICIAL GRADE. Every answer inside a room is
 * a row under an `is_practice` attempt with no exam behind it — the same
 * mechanism that protects the adaptive session, rather than a second one somebody
 * has to remember (FR-018).
 */

export type StudyRoomState = "pending" | "live" | "closed";

export interface StudyRoomBoardRow {
  /**
   * ⚠️ THE PARTICIPANT ROW'S uuid, NOT THE USER'S. The second is a platform-wide
   * identifier for somebody who may be a child, and this list is pushed to every
   * other person in the room.
   */
  uuid: string;
  name: string;
  score: number;
  answered: number;
}

export interface StudyRoomBoard {
  room_uuid: string;
  ends_at: string;
  rows: StudyRoomBoardRow[];
}

export interface StudyRoom {
  uuid: string;
  /** The invitation IS the uuid; there is no second code and no invitee list. */
  invite_url: string;
  /**
   * ⚠️ READ FROM THE PAYLOAD, NEVER DERIVED HERE. Closure is a comparison against
   * the SERVER's clock, and a browser a minute fast would show «انتهت» over a room
   * still taking answers, or the reverse. The same rule 018 wrote down after a
   * recording was re-derived in TypeScript and became unreachable.
   */
  state: StudyRoomState;
  state_label: string;
  starts_at: string;
  ends_at: string;
  question_count: number;
  max_participants: number;
  duration_minutes: number;
  concept?: { uuid: string; name: string };
  host: { uuid: string; name: string };
  /** Present on creation only — see FR-023: a short paper is an answer. */
  requested_count?: number;
  /** Null for a room the reader hosts but never played in. */
  score: number | null;
  answered: number | null;
  finished_at: string | null;
}

export interface StudyRoomQuestion {
  /** ⚠️ The address of an answer, and NOT `order` — two items can share an order. */
  question_id: number;
  order: number;
  content: string;
  points: number;
  difficulty: AdaptiveDifficulty | null;
  options: { id: number; content: string }[];
  answered: boolean;
  /* Present once answered, and never before — the payload IS the guard. */
  selected_option_ids?: number[];
  is_correct?: boolean;
  correct_option_ids?: number[];
  explanation?: string | null;
}

export interface StudyRoomView {
  room: StudyRoom;
  board: StudyRoomBoard;
  questions: StudyRoomQuestion[];
}

export interface StudyRoomAnswer {
  result: {
    is_correct: boolean;
    correct_option_ids: number[];
    explanation: string | null;
  };
  room: StudyRoom;
  /** True only for the answer that COMPLETED the set — never for a room that ran out. */
  finished: boolean;
}

export interface StudyRoomDraft {
  teacher: string;
  concept?: string;
  difficulty?: AdaptiveDifficulty;
  question_count: number;
  max_participants: number;
  duration_minutes: number;
  starts_in_minutes: number;
}

export const studyRooms = {
  mine: () => api.get<{ data: StudyRoom[] }>("/study-rooms"),

  create: (draft: StudyRoomDraft) => api.post<{ data: StudyRoom }>("/study-rooms", draft),

  join: (uuid: string) =>
    api.post<{ data: StudyRoom; resumed: boolean }>(`/study-rooms/${uuid}/join`, {}),

  show: (uuid: string) => api.get<{ data: StudyRoomView }>(`/study-rooms/${uuid}`),

  answer: (uuid: string, question_id: number, option_ids: number[]) =>
    api.post<{ data: StudyRoomAnswer }>(`/study-rooms/${uuid}/answer`, {
      question_id,
      option_ids,
    }),

  /**
   * ⚠️ THE FALLBACK FOR A CLIENT WITH NO SOCKET, and the same shape the frame
   * carries. SC-015's rule from 010: the screen works without live delivery and
   * is merely a refresh behind.
   */
  board: (uuid: string) => api.get<{ data: StudyRoomBoard }>(`/study-rooms/${uuid}/board`),
};

/** The channel the board is pushed on. Named once, here and in `channels.php`. */
export function boardChannel(uuid: string): string {
  return `study-room-board.${uuid}`;
}
