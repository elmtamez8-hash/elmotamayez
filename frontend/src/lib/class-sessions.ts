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
  recording: { status: string; lesson_uuid: string | null } | null;
}

/** Arabic for a recording state. The raw value is a machine word, not a message. */
export function recordingLabel(status: string): string {
  const labels: Record<string, string> = {
    pending: "قيد الانتظار",
    ingesting: "قيد المعالجة",
    published: "منشور",
    failed: "تعذّر النشر — يمكنك الرفع يدوياً",
    // The session belongs to a subject rather than a course, so there is no
    // course tree for the lesson to live in.
    no_course: "التسجيل جاهز، ولا كورس لنشره فيه",
  };

  return labels[status] ?? status;
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

export interface AttendanceRow {
  uuid: string;
  status: string;
  status_label: string;
  source: string;
  source_label: string;
  /** What the system concluded. Survives any override, on purpose (FR-025). */
  auto_status: string | null;
  auto_status_label: string | null;
  first_joined_at: string | null;
  stay_seconds: number;
  was_overridden: boolean;
  override_reason: string | null;
  overridden_at: string | null;
  /** An independent fact that never moved the status (FR-021د). */
  recording_watched_at: string | null;
  student?: { uuid: string; name: string } | null;
}

export const attendance = {
  list: (sessionUuid: string) =>
    api.get<{ data: AttendanceRow[] }>(`/class-sessions/${sessionUuid}/attendance`),

  override: (uuid: string, status: string, reason: string) =>
    api.post<AttendanceRow>(`/attendances/${uuid}/override`, { status, reason }),

  /**
   * The teacher's remarks on this session's students.
   *
   * A batch, because the register is filled in one pass — and optional, because
   * the report goes out on attendance alone rather than waiting for one.
   */
  feedback: (
    sessionUuid: string,
    entries: Array<{ student_uuid: string; rating?: number | null; note?: string | null }>,
  ) => api.post<{ data: unknown[] }>(`/class-sessions/${sessionUuid}/feedback`, { entries }),
};

export interface FreezePeriod {
  uuid: string;
  starts_on: string;
  ends_on: string;
  reason: string | null;
  /** Absent means the whole workspace — every student of this teacher. */
  student?: { uuid: string; name: string } | null;
  creator?: { uuid: string; name: string } | null;
}

export interface FreezeResult {
  data: FreezePeriod;
  /** What the freeze took away. Shown, never swallowed. */
  suspended: ClassSession[];
  notified: number;
}

export const freezePeriods = {
  list: () => api.get<{ data: FreezePeriod[] }>("/freeze-periods"),

  create: (body: {
    starts_on: string;
    ends_on: string;
    student_uuid?: string;
    reason?: string;
  }) => api.post<FreezeResult>("/freeze-periods", body),

  remove: (uuid: string) => api.delete<{ deleted: boolean }>(`/freeze-periods/${uuid}`),
};

export interface GenerateResult {
  created: ClassSession[];
  /** Reported, never swallowed: the teacher must see which slots were skipped. */
  skipped: Array<{ starts_at: string; reason: string }>;
}

/**
 * ⚠️ TWO REFUSALS, KEPT APART. A student can be short of credit AND short of
 * homework at once; folding them into one string would tell them whichever the
 * server checked first, and send them to do the wrong thing.
 */
export interface SessionEligibility {
  session_uuid: string;
  open: boolean;
  unlock: {
    open: boolean;
    reason: string | null;
    missing: ("attendance" | "assignment" | "score")[];
    rule_scope: "default" | "course" | "none";
    exempt: boolean;
  };
  booking_refusal: string | null;
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

  /** One session, off the weekly pattern (FR-002). */
  create: (body: {
    teacher_profile_id: string;
    title: string;
    type: "individual" | "group";
    starts_at: string;
    duration_minutes: number;
    seats_total: number;
  }) => api.post<ClassSession>("/class-sessions", body),

  /**
   * Whether `type` may still change is the server's call, not this one's: it
   * depends on whether a seat is taken, which the client cannot see reliably.
   */
  update: (
    uuid: string,
    body: Partial<{ title: string; seats_total: number; starts_at: string; duration_minutes: number }>,
  ) => api.put<ClassSession>(`/class-sessions/${uuid}`, body),

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

  /**
   * May I open this one, and if not, what exactly is missing? (FR-038)
   *
   * ⚠️ ASKED PER SESSION AND ONLY WHERE ONE IS BEING OPENED. The gate itself is
   * bulk on the server; this is the single door. Calling it in a list would be
   * the N+1 the server-side reader exists to prevent, moved into the browser.
   */
  eligibility: (uuid: string) =>
    api.get<{ data: SessionEligibility }>(`/class-sessions/${uuid}/eligibility`),

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
