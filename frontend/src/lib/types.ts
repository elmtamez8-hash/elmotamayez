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
  grade_level_slug: string;
  registered_by_parent: boolean;
  terms_accepted: boolean;
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
  created_at: string;
}

export interface Course {
  uuid: string;
  title: string;
  slug: string;
  description: string;
  price: number;
  currency: string;
  status: string;
  visibility: string;
  is_sequential: boolean;
  is_free: boolean;
  language: string;
  duration_seconds: number;
  created_at: string;
}

export interface Enrollment {
  uuid: string;
  course_id: number;
  student_user_id: number;
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
  amount: number;
  currency: string;
  provider: string;
  status: string;
  rejection_reason: string | null;
  approved_at: string | null;
  course_title: string | null;
  has_receipt: boolean;
  is_mine: boolean;
  receipt_url: string | null;
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
  is_current: boolean;
  created_at: string;
}
