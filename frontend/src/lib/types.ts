/** Null for accounts created through the academy-signup path. */
export type PlatformRole = "student" | "teacher" | "parent";

export interface StudentRegistration {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
  password_confirmation: string;
  phone: string;
  country: string;
  /*
   * Spec 022 · FR-005. ⚠️ THE YEAR, AND `grade_level_slug` IS GONE — not
   * optional. The student's broad stage is DERIVED from the year on the server,
   * and sending both would be two stored answers to one question. The API
   * refuses an unknown key silently by ignoring it, so a stale field here would
   * simply never arrive anywhere.
   */
  school_year_slug: string;
  // Spec 011 · FR-042. Required by the API: an empty string is a 422, which is
  // why the form defaults it to the first region rather than to a placeholder.
  region_slug: string;
  registered_by_parent: boolean;
  terms_accepted: boolean;
  /*
   * Spec 013 · FR-009. ⚠️ REQUIRED BY THE API AND MISSING FROM THIS INTERFACE
   * UNTIL SPEC 011 — so every student self-registration was answered 422 with
   * the message under a field the form did not draw. The guardian's number is
   * required only for an applicant under eighteen, which the API decides from
   * the date in the same payload.
   */
  date_of_birth: string;
  guardian_contact?: string;
}

export interface ParentRegistration {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
  password_confirmation: string;
  phone: string;
  country: string;
  terms_accepted: boolean;
}

/** A child on a parent's account. `has_account` says whether the child signed up
 * separately; the child's own uuid is deliberately not exposed here. */
/**
 * One guardian-to-student relation, as `/family/relations` sends it.
 *
 * ⚠️ THE SHAPE FOLLOWS `ParentStudentRelationResource`, NOT THE OLD
 * `/parent/children` PAYLOAD. That route was removed by spec 003 and this type
 * described it for three specs afterwards, while the screen using it answered
 * 404 on load and on submit.
 */
export interface ChildLink {
  uuid: string;
  student_name: string;
  student_age: number | null;
  // The broad stage — derived server-side from the year below (spec 022).
  student_grade_level_slug: string | null;
  student_school_year_slug: string | null;
  student_school_year_name: string | null;
  student_has_account: boolean;
  relation_type: string;
  status: string;
}

export interface NotificationPreferences {
  weekly_reports: boolean;
  session_alerts: boolean;
}

export interface TeacherRegistration {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
  password_confirmation: string;
  phone: string;
  country: string;
  terms_accepted: boolean;
}

/** The wizard's saved state — step_data is deliberately loose: it mirrors
 * whatever the four steps have answered so far, and the component narrows it. */
export interface TeacherApplication {
  status: string;
  current_step: number;
  step_data: Record<string, Record<string, unknown>>;
  rejection_reason: string | null;
}

export interface User {
  uuid: string;
  first_name: string;
  last_name: string;
  name: string;
  email: string;
  email_verified_at: string | null;
  status: string;
  is_super_admin: boolean;
  platform_role: PlatformRole | null;
  last_workspace_id: number | null;
  /**
   * What this person may do IN THE WORKSPACE THEY ARE IN — names from
   * `lib/permissions.ts`, never spelled inline.
   *
   * ⚠️ It decides what is OFFERED, never what is allowed. Every one of these is
   * enforced by a policy on the server, and the client's copy exists so the
   * panel stops showing a student the course editor.
   */
  permissions: string[];
  created_at: string;
}

export interface Course {
  uuid: string;
  title: string;
  /**
   * ⚠️ REQUIRED ON EVERY WRITE SINCE IT WAS FOUND NULL ON 77 OF 77 ROWS. The
   * column arrived with 007's pricing migration and nothing ever wrote it, so
   * every screen that groups by subject was grouping nothing. Optional in the
   * type because lists do not eager-load the relation.
   */
  subject?: { uuid: string; label: string } | null;
  slug: string;
  description: string;
  /** Minor units — 4999 is 49.99. Format with formatMinorMoney, never directly. */
  price_minor: number;
  currency: string;
  status: string;
  visibility: string;
  is_sequential: boolean;
  is_free: boolean;
  language: string;
  duration_seconds: number;
  created_at: string;
  /**
   * The promo video as its OWNER sees it (018). The status travels on this
   * resource and never on the public one: the teacher needs to know why their
   * button is not showing, and a visitor told something is hidden pending
   * approval has been told it exists.
   */
  promo_video_id?: string | null;
  promo_video_status?: "none" | "pending" | "approved" | "rejected";
}

// Mirrors EnrollmentResource exactly. It flattens the course into two fields
// rather than nesting it — `course_id` and `student_user_id` are never sent, and
// reading them rendered "كورس رقم " with nothing after it.
export interface Enrollment {
  uuid: string;
  course_uuid: string;
  course_title: string;
  /** Whose workspace the course belongs to — the private chat is opened by it. */
  workspace_uuid: string | null;
  teacher_name: string | null;
  source: string;
  status: string;
  progress_pct: number;
  enrolled_at: string;
  completed_at: string | null;
}

export interface Exam {
  uuid: string;
  course_id: number | null;
  title: string;
  description: string;
  duration_minutes: number;
  passing_score: number;
  max_attempts: number;
  status: string;
  is_published: boolean;
  questions_count?: number;
}

export interface Attempt {
  uuid: string;
  status: string;
  score: number;
  max_score: number;
  passed: boolean;
  started_at: string;
  submitted_at: string | null;
}

export interface Certificate {
  uuid: string;
  certificate_number: string;
  verification_code: string;
  issue_reason: string;
  issued_at: string;
  course_title: string | null;
  student_name: string | null;
}

export interface Order {
  uuid: string;
  /** Minor units. Same rule as Course.price_minor. */
  amount_minor: number;
  currency: string;
  provider: string;
  status: string;
  rejection_reason: string | null;
  approved_at: string | null;
  course_title: string | null;
  has_receipt: boolean;
  is_mine: boolean;
  receipt_url: string | null;
  /** Hours the platform promises a receipt review in — null once decided. */
  review_sla_hours: number | null;
  created_at: string;
}

export interface Workspace {
  uuid: string;
  name: string;
  slug: string;
  type: string;
  settings: Record<string, unknown> | null;
  is_owner: boolean;
  /** Present only when the workspace came from the membership list. */
  pivot_role?: string;
  /** Arabic for `pivot_role`, from the server — see `roleLabel()`. */
  pivot_role_label?: string | null;
  is_current: boolean;
  created_at: string;
}
