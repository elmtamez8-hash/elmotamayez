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
  /*
   * ⚠️ THE READ, AND IT IS A DIFFERENT QUESTION FROM THE TWO BELOW.
   * `ExamController::index()` asks `exams.view` to decide whether the list is
   * the AUTHOR'S — drafts included, unfiltered by enrolment — or the student's
   * own slice. So it is what gates «إدارة الاختبارات», while `exams.create`
   * gates the button inside it: a reader who may see the drafts but not write
   * one still needs the screen.
   */
  examsView: "exams.view",
  examsCreate: "exams.create",
  examsUpdate: "exams.update",
  /*
   * ⚠️ `.all`, AND THE DOT SHAPE IS `certificates.view.all` — the students' half.
   * `CertificateController::index()` narrows to `student_user_id = me` for
   * anyone without it, which is precisely why one screen could not serve both:
   * the same request answers «my certificates» or «my students' certificates»
   * depending on the reader, and the page rendered both as the first.
   */
  certificatesViewAll: "certificates.view.all",
  sessionsManage: "sessions.manage",
  settlementStatement: "settlement.statement.view",
  bankView: "bank.view",
  analyticsView: "analytics.view",
  /*
   * ⚠️ A DIFFERENT PERMISSION FROM THE ONE ABOVE, AND THE DISTANCE IS THE WHOLE
   * PLATFORM. `analytics.view` is a workspace permission an assistant holds —
   * «your own teacher's numbers»; this one adds every workspace together and is
   * held by no tenant role at all (spec 011 · FR-046).
   */
  analyticsCrossTeacher: "analytics.cross_teacher.view",
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
  /*
   * ⚠️ SEPARATE FROM `membersView`, AND IT GATES A CONTROL RATHER THAN A
   * LINK. Reading the team is one question; changing what somebody on it
   * may do is another, and an owner may delegate inviting without
   * delegating promotion. Until 2026-09-05 this name was read by nothing
   * at all — on either side of the wire.
   */
  membersUpdate: "members.update",
  /*
   * ⚠️ A PLATFORM PERMISSION ON A TEACHER-SHAPED SCREEN, AND BOTH HALVES
   * ARE TRUE. Raising a ceiling creates a debt the platform carries alone,
   * so no teacher holds it — but the endpoint asks for the workspace in
   * context and an active enrolment in it, so the officer does the work
   * from the teacher's own student list. Read by nothing until 2026-09-05.
   */
  billingLimitManage: "billing.limit.manage",
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
  // Spec 011 · US1. Two names, not one: pricing the goods and packing them are
  // two jobs, and this is the one a teacher delegates — an assistant who posts
  // the parcels reads a home address and has no business setting a shelf price.
  storeItemsManage: "store.items.manage",
  /*
   * Spec 011 · US4. The teacher writes the duration and the coverage; the PRICE
   * is `plans.price`, which is platform-level and deliberately absent from this
   * map — no screen in this application may offer it.
   */
  plansManage: "plans.manage",
  storeShipmentsManage: "store.shipments.manage",
  // Spec 010 — writing the periodic assessment AND weighting the grade
  // components. One name for both on purpose: the weights decide the single
  // number on the same document that reaches the same guardian, and a second
  // constant would need a fifth backfill migration for roles that already exist.
  reviewsPeriodicManage: "reviews.periodic.manage",
  // ⚠️ AND THIS ONE IS ITS OWN NAME RATHER THAN `chat.reply` REUSED, which is the
  // opposite call from the line above and for a stated reason: answering a
  // question in a thread a student opened, and sending three hundred families a
  // message nobody can reply to, are different powers over the same people.
  announcementsManage: "announcements.manage",
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

/**
 * Whether this reader may open this path at all, judged by the sidebar's own map.
 *
 * ⚠️ THE SIDEBAR HID THE LINKS AND THE ADDRESS BAR DID NOT. Every `/manage/*`
 * screen rendered in full for a signed-in STUDENT who typed its URL — the
 * teacher's session calendar with «دخول الغرفة», the grading board, the
 * assistants team. Nothing leaked, because the server refuses each read; what
 * the student got instead was «تعذّر تحميل البيانات — تحقّق من اتصالك», a 403
 * dressed as a network fault, under a heading about their students' balances.
 * Found by walking the product as a student on 2026-08-27, from the same report
 * as the teacher's profile-URL card on /settings.
 *
 * ⚠️ THE NAV IS PASSED IN, NEVER COPIED. The permission that hides a link and
 * the permission that closes the screen behind it must be one answer; a
 * `MANAGE_PERMISSIONS` list beside this would age at the first entry anybody
 * adds, and it would age silently in the open direction. The shell layout hands
 * it its own array — the same one it filters the sidebar with.
 *
 * Longest match wins, so `/manage/billing/students` is judged by its own entry
 * rather than by `/manage/billing/settings`; a path no entry covers stays open,
 * which is every student screen plus the deep links (`/sessions/{uuid}/room`)
 * that are nobody's menu item.
 *
 * It is not a security boundary and does not pretend to be one: the server is.
 * This is the difference between «not yours» and «check your connection».
 */
export function refusedBy(
  nav: readonly { href: string; permission?: string; linkOnly?: boolean }[],
  pathname: string,
  user: { permissions?: string[] } | null,
): boolean {
  const gate = nav
    .filter(({ permission, href, linkOnly }) =>
      permission !== undefined
      && linkOnly !== true
      && (pathname === href || pathname.startsWith(href + "/")))
    .sort((a, b) => b.href.length - a.href.length)[0];

  return gate !== undefined && !can(user, gate.permission);
}
