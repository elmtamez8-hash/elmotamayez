import { api } from "./api";

/**
 * Taught sessions: the calendar, the seats, the student's timetable.
 *
 * Named class-sessions, not sessions — `lib/sessions.ts` is the auth sessions of
 * spec 004, and the collision is exactly the one the backend avoids by calling
 * the model ClassSession. Two things called Session in one product is a line
 * every reader misreads once.
 *
 * The types below mirror ClassSessionResource and SessionBookingResource field
 * for field. Read the PHP resource before changing one: a type that claims a
 * field the API does not send renders a blank with no error anywhere, which is
 * how /enrollments spent four phases showing "كورس رقم " and nothing after it.
 */

export type ClassSessionStatus =
  | "scheduled"
  | "live"
  | "interrupted"
  | "completed"
  | "cancelled"
  | "suspended";

export type BookingStatus =
  | "booked"
  | "cancelled_in_window"
  | "cancelled_late"
  | "released";

export interface ClassSession {
  uuid: string;
  title: string;
  type: "individual" | "group";
  type_label: string;
  status: ClassSessionStatus;
  status_label: string;
  starts_at: string;
  ends_at: string;
  duration_minutes: number;
  /** Declared by the server, so a countdown cannot render the wrong zone. */
  timezone: string;
  seats: { total: number; taken: number; available: number };
  my_booking: {
    uuid: string;
    status: BookingStatus;
    status_label: string;
    may_cancel_until: string;
  } | null;
  course?: { uuid: string; title: string };
  recording: { status: string } | null;
}

export interface SessionBooking {
  uuid: string;
  status: BookingStatus;
  status_label: string;
  booked_at: string;
  cancelled_at: string | null;
  session?: ClassSession;
}

export interface JoinTicket {
  room_url: string;
  token: string;
  expires_at: string;
  role: "host" | "participant";
  /** Sent by the server: the client must not guess the heartbeat's period, since
   *  the crediting cap is derived from it. */
  presence_interval_seconds: number;
}

export interface PresenceState {
  stay_seconds: number;
  status: string;
  session_status: string;
}

export interface GenerateResult {
  created: ClassSession[];
  /** Reported, never swallowed: the teacher must see which slots were skipped. */
  skipped: Array<{ starts_at: string; reason: string }>;
}

export const classSessions = {
  list: (params: { from?: string; to?: string; status?: string } = {}) => {
    const entries = Object.entries(params).filter(
      (entry): entry is [string, string] => entry[1] !== undefined,
    );
    const query = new URLSearchParams(entries).toString();

    return api.get<{ data: ClassSession[] }>(
      `/class-sessions${query === "" ? "" : `?${query}`}`,
    );
  },

  show: (uuid: string) => api.get<ClassSession>(`/class-sessions/${uuid}`),

  generate: (body: {
    teacher_profile_id: string;
    from: string;
    to: string;
    seats_total?: number;
    type?: "individual" | "group";
    title?: string;
  }) => api.post<GenerateResult>("/class-sessions/generate", body),

  cancel: (uuid: string, reason?: string) =>
    api.post<ClassSession>(`/class-sessions/${uuid}/cancel`, { reason }),

  book: (uuid: string) => api.post<SessionBooking>(`/class-sessions/${uuid}/book`, {}),

  cancelBooking: (uuid: string) => api.delete<SessionBooking>(`/bookings/${uuid}`),

  /**
   * A ticket for the room.
   *
   * Refused with the same 403 whatever the reason — no seat, wrong time, room
   * closed. A refusal that distinguishes them tells the caller the session
   * exists and when to come back.
   */
  join: (uuid: string) => api.post<JoinTicket>(`/class-sessions/${uuid}/join`, {}),

  /** One heartbeat. The reply is what the SERVER believes, not what we sent. */
  presence: (uuid: string) =>
    api.post<PresenceState>(`/class-sessions/${uuid}/presence`, {}),

  host: (uuid: string, action: "mute" | "remove" | "end", targetUuid?: string) =>
    api.post<{ done: boolean }>(`/class-sessions/${uuid}/host/${action}`, {
      target_uuid: targetUuid,
    }),

  /** The student's own timetable, across every teacher they study with. */
  schedule: () => api.get<{ data: SessionBooking[] }>("/schedule"),

  /**
   * The next session, and how many seconds until it starts.
   *
   * The remaining seconds come from the server rather than from subtracting
   * dates in the browser: a machine whose clock is off would otherwise count
   * down to a moment that does not exist.
   */
  next: () =>
    api.get<{ data: SessionBooking | null; seconds_until_start?: number }>("/schedule/next"),
};
