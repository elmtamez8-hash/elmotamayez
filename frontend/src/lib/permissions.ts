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
  billingSettings: "billing.settings.manage",
  billingBalanceView: "billing.balance.view",
  billingExamMode: "billing.exam_mode.manage",
  membersView: "members.view",
  billingCollection: "billing.collection.view",
  billingAudit: "billing.audit.view",
  // The reconciliation screen reads the same rows as the collection report and
  // is gated on the same name — there is no separate `payments.reconcile`.
} as const;

/** Does this user hold it? A user with no list holds nothing. */
export function can(user: { permissions?: string[] } | null, permission?: string): boolean {
  if (permission === undefined) return true;

  return user?.permissions?.includes(permission) ?? false;
}
