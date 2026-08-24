/**
 * Permission names, mirroring `Tenancy\Support\Permissions` on the server.
 *
 * ⚠️ THE WIRE CARRIES STRINGS, AND THEY LIVE HERE AND NOWHERE ELSE. The backend
 * has the same rule for the same reason — a permission spelled inline in one
 * component is a permission nobody finds when it is renamed, and a screen gated
 * on a name that no longer exists is a screen that has quietly closed.
 *
 * ⚠️ AND NONE OF THIS IS A SECURITY BOUNDARY. Every one of these is enforced by
 * a policy on the server; what the list decides is whether a link is OFFERED. A
 * menu full of links that answer 403 reads as a broken product, which is exactly
 * what a student saw: the course editor, the exam builder, the question bank and
 * the settlement statement, all in their sidebar, all refused on click.
 */
export const P = {
  coursesUpdate: "courses.update",
  examsCreate: "exams.create",
  examsUpdate: "exams.update",
  sessionsManage: "sessions.manage",
  settlementStatement: "settlement.statement.view",
  bankView: "bank.view",
  analyticsView: "analytics.view",
  gradingPerform: "grading.perform",
  assignmentsManage: "assignments.manage",
  // ⚠️ UNDERSCORE, NOT A DOT. `Permissions::UNLOCK_RULES_MANAGE` is
  // `unlock_rules.manage`; this string said `unlock.rules.manage` from the day
  // US7 shipped, so the sidebar entry matched nobody and the screen was
  // reachable only by typing its address. Nothing failed — a permission name
  // that matches no permission is indistinguishable from a reader who lacks it,
  // which is why `e2e/assessments.spec.ts` clicks the link instead of `goto`.
  unlockRulesManage: "unlock_rules.manage",
  billingSettings: "billing.settings.manage",
  billingBalanceView: "billing.balance.view",
  billingExamMode: "billing.exam_mode.manage",
  membersView: "members.view",
  // Spec 010 — the assistants screen. Gated on `roles.manage` and not on a new
  // name: the matrix puts that permission on the OWNER alone, which is exactly
  // who `AssistantAssignmentPolicy` lets in, and whoever arranges the roles is
  // whoever arranges the team. A second constant would be a second answer to one
  // question, and the two drift the first time either moves.
  rolesManage: "roles.manage",
  billingCollection: "billing.collection.view",
  billingAudit: "billing.audit.view",
  // Spec 009 — the teacher's own shop and its fulfilment queue. The catalogue
  // permission is deliberately absent: it is platform-level and no tenant role
  // holds it, so offering a link to it would show every teacher a 403.
  rewardsManage: "rewards.manage",
  // Spec 010 — writing the periodic assessment AND weighting the grade
  // components. One name for both on purpose: the weights decide the single
  // number on the same document that reaches the same guardian, and a second
  // constant would need a fifth backfill migration for roles that already exist.
  reviewsPeriodicManage: "reviews.periodic.manage",
  // The reconciliation screen reads the same rows as the collection report and
  // is gated on the same name — there is no separate `payments.reconcile`.
  //
  // Spec 013 — the data-protection officer's queue. Both are PLATFORM
  // permissions held by no tenant role: a teacher who could execute an erasure
  // could destroy a student's record across every OTHER teacher they study with,
  // and a teacher who could place a hold could freeze an erasure inside their own
  // workspace and keep the data indefinitely.
  //
  // ⚠️ COPIED FROM `Tenancy\Support\Permissions` CHARACTER BY CHARACTER. A name
  // that matches no permission is indistinguishable from a reader who lacks one —
  // the sidebar entry simply never appears, nothing fails, and the screen is
  // reachable only by typing its address. That is what `unlockRulesManage` cost.
  complianceRequestsExecute: "compliance.requests.execute",
  complianceHoldsManage: "compliance.holds.manage",
  complianceOffboardingExecute: "compliance.offboarding.execute",
} as const;

/** Does this user hold it? A user with no list holds nothing. */
export function can(user: { permissions?: string[] } | null, permission?: string): boolean {
  if (permission === undefined) return true;

  return user?.permissions?.includes(permission) ?? false;
}
