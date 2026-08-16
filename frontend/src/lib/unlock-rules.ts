import { api } from "./api";

/**
 * What earns a student the session after the last one (FR-037).
 *
 * ⚠️ THE COURSE TRAVELS AS A UUID AND `null` IS THE WORKSPACE DEFAULT. The
 * server stores a zero sentinel — a nullable key would make its uniqueness index
 * inert on the one row every workspace has — but that key never leaves the
 * database: every route in this product binds by uuid, and this list is the
 * teacher's own courses, which they know by name.
 *
 * ⚠️ AND A COURSE RULE REPLACES THE DEFAULT RATHER THAN ADDING TO IT. A course
 * that switches attendance OFF must switch it off; the screen says so, because
 * a teacher who assumes the two combine will set a course rule expecting it to
 * tighten the default and watch it loosen instead.
 */

export interface UnlockRule {
  uuid: string;
  /** Null on the workspace default. */
  course_uuid: string | null;
  course_title: string | null;
  is_default: boolean;
  requires_attendance: boolean;
  requires_assignment: boolean;
  min_score_pct: number;
  is_active: boolean;
}

export interface UnlockRuleInput {
  course_uuid?: string | null;
  requires_attendance: boolean;
  requires_assignment: boolean;
  min_score_pct?: number;
  is_active?: boolean;
}

export const unlockRules = {
  list: () => api.get<{ data: UnlockRule[] }>("/manage/unlock-rules"),

  save: (input: UnlockRuleInput) => api.post<{ data: UnlockRule }>("/manage/unlock-rules", input),

  exempt: (sessionUuid: string, studentUuid: string, reason: string) =>
    api.post<{ data: { uuid: string } }>(
      `/manage/class-sessions/${sessionUuid}/unlock-exemptions`,
      { student_uuid: studentUuid, reason },
    ),
};

/** The condition in one sentence, for a row that has to be scannable. */
export function ruleSummary(rule: UnlockRule): string {
  if (!rule.is_active || (!rule.requires_attendance && !rule.requires_assignment)) {
    return "بلا شرط";
  }

  const parts: string[] = [];

  if (rule.requires_attendance) parts.push("حضور الحصة السابقة");

  if (rule.requires_assignment) {
    parts.push(
      rule.min_score_pct > 0
        ? `حلّ واجبها بـ${rule.min_score_pct}٪ على الأقل`
        : "تسليم واجبها",
    );
  }

  return parts.join(" و");
}
