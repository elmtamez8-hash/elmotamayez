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
  /**
   * The broadcast is over even if the status has not caught up yet — the close
   * job runs at the scheduled end, so a lesson ended early stays `live` in the
   * meantime. Badge and room button both read this, not the status alone.
   */
  room_closed: boolean;
  /**
   * Whether the door is open right now — ANSWERED BY THE SERVER.
   *
   * The join window is a `platform_settings` row an operator tunes and the
   * room's own closure sits inside it, so a client computing this from
   * `starts_at` and a constant offers a button the server answers «تعذّر
   * الدخول» — and goes on offering it on a machine whose clock is wrong.
   *
   * It says nothing about entitlement: a seat, a balance and a piece of
   * homework are all still asked at the door.
   */
  join_open: boolean;
  /**
   * كم ثانيةً حتّى يفتحُ الباب — `0` مفتوحٌ الآن، `null` لن يفتحَ ثانيةً.
   *
   * ⚠️ **هذا ما يجعلُ الزرَّ يظهرُ على صفحةٍ مفتوحة.** `join_open` فوقَه يُجابُ
   * مرّةً واحدةً عندَ الجلب، فطالبٌ يفتحُ جدولَه قبلَ الحصّةِ بعشرينَ دقيقةً يرى
   * العدَّ يبلغُ «بدأت الآن» والبابُ مغلقٌ حتّى يُعيدَ التحميل. والمتصفّحُ
   * يُنقِصُ رقماً وصلَه ولا يشتقُّه من `starts_at` ناقصَ ثابت: النافذةُ صفٌّ في
   * إعداداتِ المنصّةِ، وساعةُ الجهازِ قد تكونُ خطأً بساعة (SC-016).
   */
  seconds_until_join_open: number | null;
  /** العدُّ إلى البداية، من الخادمِ للسببِ نفسِه. */
  seconds_until_start: number;
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
  /**
   * اسمُ المدرّس — **حاضرٌ فقط حين حمَّله المُنادي** (`whenLoaded`).
   *
   * غيابُ المفتاحِ يعني «لم يُطلَب» لا «لا مدرّسَ لها»: قراءتُه في المَورِدِ بلا
   * تحميلٍ مسبَقٍ استعلامانِ لكلِّ صفٍّ في كلِّ تقويمٍ في المنتَج.
   */
  teacher_name?: string | null;
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

/**
 * حجزُ الابنِ كما يراهُ وليُّ الأمرِ — `ChildSessionResource` حقلاً بحقل.
 *
 * ⚠️ نوعٌ ثالثٌ ولا إعادةُ استعمالٍ لـ`SessionBooking`، وهذا هو الحارسُ الوحيدُ
 * في الواجهةِ على ضِيقِ المَورِد. المَورِدُ العامُّ يحسبُ `join_open` و
 * `my_booking` و`seats` و`recording` **عن القارئ** — فتقولُ لوليِّ الأمرِ «لا
 * حجز» عن حصّةٍ ابنُه محجوزٌ فيها، وتدعوه إلى غرفةٍ لا يدخلُها. ونوعٌ يدّعي
 * حقلاً لا يرسلُه الخادمُ يُصيِّرُ فراغاً بلا خطأٍ في أيِّ مكان.
 *
 * ⚠️ والمفتاحُ `class_session` لا `session`.
 */
export interface ChildSessionBooking {
  uuid: string;
  class_session: {
    uuid: string;
    title: string;
    starts_at: string;
    ends_at: string;
    status: ClassSessionStatus;
    status_label: string;
    /** البثُّ انتهى وإن لم تلحقْ به الحالة — يتقدّمُ على الحالةِ في الشارة. */
    room_closed: boolean;
    course: { uuid: string; title: string } | null;
    teacher_name: string | null;
  } | null;
}

/** ملخّصُ حضورِ الابنِ — الفئاتُ الأربعُ ومجموعُها والنسبةُ المشتقّةُ منها. */
export interface ChildAttendanceSummary {
  window_days: number;
  present: number;
  late: number;
  absent: number;
  excused: number;
  total: number;
  /**
   * ⚠️ `null` لا `0` حينَ لا حصصَ في النافذة. «لم تُسجَّلْ حصصٌ بعد» و«غابَ عن
   * كلِّ حصّة» جملتانِ متعاكستان، والصفرُ يطبعُ الثانيةَ عن ابنٍ لم يبدأ.
   */
  rate_pct: number | null;
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

/**
 * One person in the room, resolved from the uuid the ticket carries.
 *
 * ⚠️ IT IS NOT AN ATTENDANCE ROW, and the absence of a status here is the design.
 * Every seat holder can read this; who was marked absent, for how long they
 * stayed and why a teacher changed a mark all need `ATTENDANCE_VIEW` and stay on
 * the register.
 */
export interface RoomParticipant {
  uuid: string;
  name: string;
  /** `staff` is an assistant: they hold no seat, and calling them a student in
   *  front of the class is a lie the screen would repeat every week. */
  role: "host" | "student" | "staff";
  avatar_url: string | null;
  badges: { key: string; name_ar: string; icon: string | null }[];
  /**
   * Whether the host put this person out of THIS session.
   *
   * ⚠️ ABSENT ENTIRELY unless the reader is the host — not `false`. Whether a
   * teacher removed somebody is a moderation fact about that person, and a key
   * that is always there tells every classmate the question was asked.
   */
  is_removed?: boolean;
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

/** One teacher of the CURRENT workspace, for a picker. */
export interface WorkspaceTeacher {
  uuid: string;
  name: string;
}

export const classSessions = {
  /**
   * The teachers of the reader's own workspace.
   *
   * ⚠️ NOT the public `/teachers` listing, which spans every workspace and shows
   * only publicly-listed profiles — it would offer another academy's teachers and
   * hide the operator's own colleagues who are not listed yet.
   */
  workspaceTeachers: () => api.get<{ data: WorkspaceTeacher[] }>("/manage/teachers"),

  /**
   * ⚠️ `from`/`to` ARE PLAIN DATES, AND THE SERVER TREATS `to` AS INCLUSIVE.
   *
   * `starts_at` is a timestamp, so `<= '2026-08-26'` would bind midnight and drop
   * the whole day being asked for. The controller compares `< to + 1 day` for a
   * bare date, which is why a date is what belongs here.
   *
   * `order: "desc"` is for looking BACKWARDS — ascending over a past range is the
   * «oldest fifty» defect this exists to end, wearing the other face.
   */
  list: (
    params: {
      from?: string;
      to?: string;
      status?: string;
      /** A teacher profile uuid — a workspace may have several. */
      teacher?: string;
      /**
       * A course uuid, which is what «المجموعة» means here: no group entity
       * exists in this product, and enrolment in the course is the durable set
       * of students a session is taught to.
       */
      course?: string;
      order?: "asc" | "desc";
    } = {},
  ) => {
    const entries = Object.entries(params).filter(
      (entry): entry is [string, string] => entry[1] !== undefined,
    );
    const query = new URLSearchParams(entries).toString();

    return api.get<{ data: ClassSession[] }>(
      `/class-sessions${query === "" ? "" : `?${query}`}`,
    );
  },

  show: (uuid: string) => api.get<ClassSession>(`/class-sessions/${uuid}`),

  /**
   * Every session of ONE course, for its page's tab (021 · FR-016).
   *
   * ⚠️ NOT `list({ course })`, WHICH ANSWERS A REAL STUDENT `403`. That route is
   * the teacher's calendar: `ClassSessionPolicy::viewAny()` asks for
   * `SESSIONS_VIEW`, and a student holds no spatie team id — a member of no
   * workspace has a null context, so every permission check below it is false.
   * Built on it, the tab caught the refusal into an empty list and told a
   * student with a lesson every week «لا حصص في هذه المادّة بعد». The route a
   * student can use is the one whose guard is their ENROLMENT.
   *
   * ⚠️ AND IT IS BOUNDED FROM BOTH ENDS ON THE SERVER — «the oldest fifty».
   * `/class-sessions` paginates at fifty ascending, so an unbounded read of a
   * course in its second term returns its first fifty lessons and no upcoming
   * one at all, including the session the header above is counting down to.
   */
  forCourse: (courseUuid: string) =>
    api.get<{ data: ClassSession[] }>(`/courses/${courseUuid}/sessions`),

  /**
   * The next session of ONE course, for the header of its page (021 · FR-015).
   *
   * ⚠️ NOT `/schedule/next`, WHICH IS A DIFFERENT QUESTION. That one reads the
   * reader's own BOOKINGS across every teacher they study with — the right
   * answer for a timetable and the wrong shape for a course header, which must
   * name the next lesson of this course whether or not a seat has been taken.
   * A student who has not booked is exactly the student the header exists for.
   *
   * `data: null` is the answer for «no next session», not an error and not an
   * empty countdown.
   */
  nextForCourse: (courseUuid: string) =>
    api.get<{
      data: ClassSession | null;
      seconds_until_start?: number;
      /**
       * How long until the door opens — `0` for open now, `null` for never
       * again (a closed room, or a window already past).
       *
       * ⚠️ WITHOUT TICKING THIS DOWN THE BUTTON NEVER APPEARS ON A PAGE LEFT
       * OPEN. `join_open` is answered once, at fetch, so a student who opens the
       * course twenty minutes early watches the countdown reach «بدأت الآن»
       * while the footer still says the door is shut — until they reload.
       * Seeded by the server for the same reason the other countdown is: the
       * browser may tick a number down, never derive it from a clock that may
       * be an hour out (SC-016).
       */
      seconds_until_join_open?: number | null;
    }>(`/courses/${courseUuid}/next-session`),

  /**
   * One session, off the weekly pattern (FR-002).
   *
   * ⚠️ UUIDS, AND `course_uuid` IS REQUIRED. Both used to be raw autoincrement
   * ids — and `course_id` was absent from this type entirely while the server has
   * required it since spec 006 (the session price is a property of the course, so
   * a session with no course has no price and can never consume a credit). The
   * payload was therefore STRUCTURALLY INCAPABLE of succeeding: every call came
   * back 422 naming a field the screen did not offer.
   */
  create: (body: {
    teacher_profile_uuid?: string;
    course_uuid: string;
    title: string;
    type: "individual" | "group";
    /**
     * ⚠️ REQUIRED FOR A GROUP LESSON, AND THE SERVER REFUSES WITHOUT IT.
     * Every group session created here used to be born with no group, and the
     * teacher's first group then took all of them out of every student's
     * discovery list at once — with the «حصص محجوبة» panel refusing to file any
     * that had already been taught. An individual slot has no student yet, so it
     * has no one-seat group to belong to; it gets one at booking.
     */
    cohort_uuid?: string;
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
    teacher_profile_uuid?: string;
    course_uuid: string;
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

  /**
   * The people behind the uuids the provider echoes into the room.
   *
   * ⚠️ FETCHED ONCE PER ROOM, NOT PER PARTICIPANT. It answers for everyone who
   * could be in the session, so a person arriving later is already in the map —
   * a lookup per new participant would be an N+1 driven by whoever joins.
   */
  participants: (uuid: string) =>
    api.get<{ data: RoomParticipant[] }>(`/class-sessions/${uuid}/participants`),

  /** One heartbeat. The reply is what the SERVER believes, not what we sent. */
  presence: (uuid: string) =>
    api.post<PresenceState>(`/class-sessions/${uuid}/presence`, {}),

  /**
   * ⚠️ THE BULK FORMS ARE ONE REQUEST, NEVER A LOOP HERE. Twenty presses of
   * «كتم» is twenty requests and twenty chances for one to fail in the middle,
   * leaving the room half muted with nothing saying which half. The server walks
   * its own participant list — and it is the only side that can tell a student
   * from the recorder, which is a participant too.
   */
  host: (
    uuid: string,
    action: "mute" | "remove" | "end" | "mute-all" | "remove-all" | "lower-hands" | "readmit",
    targetUuid?: string,
  ) =>
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

  /**
   * جدولُ ابنٍ بعينِه لوليِّ أمرٍ مأذون (٠٢٩ · `FR-019`).
   *
   * ⚠️ `?student=` **إلزاميّةٌ ومسارٌ منفصل**، لا معاملٌ اختياريٌّ على
   * `schedule()` فوقَها. الغيابُ هناكَ هو التصريحُ نفسُه: لا معاملَ يُطلَبُ به
   * جدولُ غيرِك، ومعاملٌ اختياريٌّ يجعلُ أكثرَ الطلباتِ شيوعاً في المنتَجِ مساراً
   * يمرُّ بجوارِ فحصِ إذنٍ في كلِّ نداء. والرفضُ `403` لا `404`، فلا يقولُ الردُّ
   * إن كان هذا المعرَّفُ يسمّي شخصاً حقيقيّاً.
   */
  childSchedule: (studentUuid: string) =>
    api.get<{ data: ChildSessionBooking[] }>(
      `/schedule/children?student=${encodeURIComponent(studentUuid)}`,
    ),

  /**
   * حضورُ ابنٍ في نافذةٍ من الأيّام (٠٢٩ · `FR-019`).
   *
   * يعبُرُ مساحاتِ العملِ عمداً: للابنِ الذي يدرسُ عندَ ثلاثةِ مدرّسينَ سجلُّ
   * حضورٍ **واحد**، وجوابٌ لكلِّ مساحةٍ على حِدَةٍ ليس الحقيقةَ التي سُئِل عنها.
   */
  childAttendance: (studentUuid: string, days?: number) =>
    api.get<{ data: ChildAttendanceSummary }>(
      `/attendance/children/summary?student=${encodeURIComponent(studentUuid)}${
        days === undefined ? "" : `&days=${days}`
      }`,
    ),
};
