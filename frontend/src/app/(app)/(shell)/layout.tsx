"use client";

import { isLearner, useAuth } from "@/lib/auth-context";
import { useRouter, usePathname } from "next/navigation";
import { useEffect, useState, type ReactNode, type ComponentType } from "react";
import Link from "next/link";
import { usePlatformName } from "@/lib/platform-context";
import { BrandMarkDecorative } from "@/components/ui/BrandMark";
import { Alert } from "@/components/ui/Alert";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { NotificationBell } from "@/components/app/NotificationBell";
import { ServiceWorkerRegistrar } from "@/components/app/ServiceWorkerRegistrar";
import { P, can, refusedBy } from "@/lib/permissions";
import { TONE_CLASSES } from "@/lib/labels";
import { grading } from "@/lib/grading";
import {
  AssignmentIcon,
  BellIcon,
  CertificateIcon,
  ChevronEndIcon,
  ChevronStartIcon,
  CloseIcon,
  CoursesIcon,
  CreditsIcon,
  FamilyIcon,
  ShieldIcon,
  GradingIcon,
  HomeIcon,
  ExamIcon,
  LearningIcon,
  LockIcon,
  LogoutIcon,
  SiteIcon,
  MembersIcon,
  MessagesIcon,
  MenuIcon,
  MistakesIcon,
  OrdersIcon,
  PracticeIcon,
  ItemAnalysisIcon,
  LeaderboardIcon,
  ProgressIcon,
  QuestionBankIcon,
  ShipmentIcon,
  ReferralIcon,
  ShopIcon,
  StoreIcon,
  ScheduleIcon,
  SessionsIcon,
  SettingsIcon,
  SettlementIcon,
  WorkspaceIcon,
  type IconProps,
} from "@/components/icons";

/**
 * `permission` absent means everyone who is signed in may see it.
 *
 * ⚠️ THE LIST USED TO BE FLAT AND UNGATED, and that is what the student was
 * complaining about: the course editor, the exam builder, the question bank, the
 * settlement statement and the whole «الإدارة» block were offered to every
 * account. The server refused each one — this was never an authorisation hole —
 * but a menu of links that answer 403 teaches the reader that the product does
 * not know who they are.
 */
type NavItem = {
  href: string;
  label: string;
  Icon: ComponentType<IconProps>;
  permission?: string;
  /**
   * Hide the link, leave the route open.
   *
   * ⚠️ ONE ENTRY USES THIS AND IT IS NOT A LOOPHOLE. `/workspaces/new` is how a
   * person with no workspace makes their first one, and holding no workspace
   * means holding no workspace permission — so gating the ROUTE the way every
   * other entry is gated would lock out precisely the reader it exists for.
   * `POST /workspaces` is ungated on the server for the same reason.
   */
  linkOnly?: boolean;
  /** Renders the waiting count beside the label — see `pendingGrading` below. */
  badge?: "grading";
  /**
   * Shown to the learning side only. Absent means everybody who passes the
   * permission gate above.
   *
   * ⚠️ THE SECOND DIRECTION, AND THE ONE THAT COULD NOT BE SPELLED AS A
   * PERMISSION. Hiding a teacher's tools from a student already works — those
   * entries carry a `permission` and a student holds none. The reverse has no
   * such gate: a student is a member of no workspace, so «the student's own
   * screens» are ungated BY NECESSITY, and every one of them was therefore
   * offered to the teacher too — a sidebar where «دفتر أخطائي» and «درّب نفسك»
   * sat between the question bank and the grading board.
   *
   * ⚠️ IT IS A TAG ON THE ITEM, NOT A SECOND ARRAY. `allNav` is what
   * `refusedBy()` walks to decide whether a typed URL is refused politely
   * instead of as a broken page; a list lifted out of that union loses its
   * route guard silently, and no test in this repository would notice.
   */
  audience?: "learner";
};

const mainNav: NavItem[] = [
  { href: "/dashboard", label: "لوحة التحكم", Icon: HomeIcon },
  // /courses is the public marketplace listing; course management lives under
  // /manage so the two do not resolve to the same route.
  { href: "/manage/courses", label: "الكورسات", Icon: CoursesIcon, permission: P.coursesUpdate },
  // /schedule is the student's own timetable across every teacher;
  // /manage/sessions is the teacher's calendar. Two screens, two audiences —
  // collapsing them into one route would make each show the other half nothing.
  { href: "/schedule", label: "جدولي", Icon: ScheduleIcon, audience: "learner" },
  { href: "/manage/sessions", label: "حصصي", Icon: SessionsIcon, permission: P.sessionsManage },
  // ⚠️ ITS OWN ENTRY, BECAUSE A SURFACE NOTHING LINKS TO IS A SURFACE NOBODY HAS.
  // The queue has a deadline running on every row — a screen reachable only by
  // typing its address is one whose requests expire unanswered, and the student
  // is told «انتهت المهلة» about a lesson the teacher meant to give them.
  { href: "/manage/private-sessions", label: "طلبات الحصص الخاصة", Icon: SessionsIcon, permission: P.sessionsManage },
  // The teacher's own money. /orders is the student's side and is a different
  // question with different permissions — SETTLEMENT_STATEMENT_VIEW reaches only
  // the teacher, never their assistant.
  { href: "/manage/settlement", label: "كشف التسوية", Icon: SettlementIcon, permission: P.settlementStatement },
  { href: "/enrollments", label: "تعلّمي", Icon: LearningIcon, audience: "learner" },
  // ⚠️ The student's own notebook, and it needs its own entry. It is derived
  // from answers rather than authored, so nothing in the product would ever link
  // to it — a screen reachable only by typing its address is a screen nobody
  // opens.
  { href: "/mistakes", label: "دفتر أخطائي", Icon: MistakesIcon, audience: "learner" },
  // Building your own paper is a different act from reading what you got wrong:
  // one starts from the bank and the other from your own history. Two entries,
  // because a student who wants to revise a topic they have never been tested on
  // would never look for it inside a notebook of mistakes.
  { href: "/practice", label: "درّب نفسك", Icon: PracticeIcon, audience: "learner" },
  /*
   * ⚠️ TWO ENTRIES BECAUSE «الاختبارات» WAS TWO SCREENS WEARING ONE HEADING.
   * `ExamController::index()` already answered two different questions — the
   * author's list with its drafts, or the student's own slice — and the page
   * rendered both the same way, so a teacher's draft sat under «ابدأ الاختبار»
   * and a student was offered «اختبار جديد» on a paper they were about to sit.
   * The buttons were hidden by permission; the HEADING, the empty state and the
   * whole reading of the screen were not, and no permission can fix a sentence
   * addressed to the wrong person.
   *
   * ⚠️ AND THE MANAGE HALF IS GATED ON `exams.view`, NOT `exams.create` — the
   * same name the controller branches on. Two spellings of one question is how
   * one answer reaches the screen and another reaches the door.
   */
  { href: "/exams", label: "الاختبارات", Icon: ExamIcon, audience: "learner" },
  { href: "/manage/exams", label: "إدارة الاختبارات", Icon: ExamIcon, permission: P.examsView },
  // The teacher's own question library. Separate from /exams, which is the
  // student's list of what they may sit: one question here serves three exams
  // there, and collapsing them would make the bank look like a fourth exam.
  { href: "/manage/bank", label: "بنك الأسئلة", Icon: QuestionBankIcon, permission: P.bankView },
  // ⚠️ The analysis needs its own entry, not a tab inside the bank. It answers a
  // different question — "which of these is failing my students" rather than
  // "what do I have" — and a screen reachable only from another screen is a
  // screen nobody opens.
  { href: "/manage/analytics/questions", label: "تحليل الأسئلة", Icon: ItemAnalysisIcon, permission: P.analyticsView },
  // ⚠️ WITH ITS COUNT, and the count is the whole reason the entry earns a
  // place. A paper waiting to be marked is a student waiting for a result they
  // were told was coming — and unlike every other screen here, nothing else in
  // the product tells the teacher it is there. A link with no number is one they
  // remember to open on the days they were already going to.
  { href: "/manage/grading", label: "لوحة التصحيح", Icon: GradingIcon, permission: P.gradingPerform, badge: "grading" },
  // ⚠️ TWO ENTRIES FOR HOMEWORK, NOT ONE (T160). "واجباتي" is what a student
  // owes; "الواجبات" is what a teacher set and has to mark. One shared link
  // whose meaning flipped with the reader's permission is the shape that made a
  // student's sidebar offer them the exam builder.
  { href: "/assignments", label: "واجباتي", Icon: AssignmentIcon, audience: "learner" },
  { href: "/manage/assignments", label: "الواجبات", Icon: AssignmentIcon, permission: P.assignmentsManage },
  // ⚠️ ITS OWN ENTRY, not a tab inside the session calendar. It answers a
  // question about the WHOLE course — what earns the next class — and a screen
  // reachable only from one session reads as a setting on that session.
  { href: "/manage/unlock-rules", label: "شرط فتح الحصة", Icon: LockIcon, permission: P.unlockRulesManage },
  /*
   * Spec 009. Three entries for the student, and none of them folded into
   * another screen.
   *
   * ⚠️ «تقدّمي» IS NOT A TAB ON THE DASHBOARD. It is where the focus timer lives,
   * and a timer a student has to go looking for is a timer nobody starts.
   * «الصدارة» is a different question from «تقدّمي» — one is about me, the other
   * about where I stand — and the shop is where the points stop being decoration:
   * the source document is explicit that points with nowhere to spend them lose
   * their meaning within two weeks, and a shop reachable only by typing its
   * address is a shop with nowhere to spend them.
   */
  { href: "/progress", label: "تقدّمي", Icon: ProgressIcon, audience: "learner" },
  /*
   * Spec 010 · US4. No permission: every signed-in person has a side of this —
   * a student reads their own, a guardian reads a child's through the same
   * screen, and a teacher simply sees an empty list. `manage/students/{uuid}/reviews`
   * is the writing side and is reached from the class register, where the
   * teacher already knows whose row they clicked.
   */
  { href: "/reviews", label: "تقييماتي الدورية", Icon: ProgressIcon, audience: "learner" },
  /*
   * Spec 010 · US5. The same reasoning as the line above — a student reads their
   * own cards, a guardian reads a child's through the same screen, and a teacher
   * sees an empty list because the card is not theirs to hold. Their side is the
   * weightings below, and their own segment on the student's page.
   */
  { href: "/report-cards", label: "كشف التقديرات", Icon: ProgressIcon, audience: "learner" },
  {
    href: "/manage/grading-schemes",
    label: "أوزان التقدير",
    Icon: ProgressIcon,
    permission: P.reviewsPeriodicManage,
  },
  /*
   * Spec 010 · US6. The publisher's side and the only side: a recipient reads an
   * announcement in the notification centre, so there is no student screen to
   * link to here.
   */
  {
    href: "/manage/announcements",
    label: "الإعلانات",
    Icon: BellIcon,
    permission: P.announcementsManage,
  },
  { href: "/leaderboard", label: "لوحة الصدارة", Icon: LeaderboardIcon, audience: "learner" },
  { href: "/shop", label: "متجر المكافآت", Icon: ShopIcon, audience: "learner" },
  // The teacher's side of that shop, and the queue of what has been claimed.
  /*
   * ⚠️ «مكافآت الطلاب», NOT «متجر مكافآتي» — a possessive on the TEACHER'S entry,
   * one letter from the learner's «متجر المكافآت» above it. The screen is what a
   * teacher STOCKS for their students plus the redemption queue they fulfil (its
   * own subtitle says so), so the possessive named the wrong owner and named it
   * in the voice this repository reserves for the learner half: «شهاداتي» is the
   * student's and «شهادات الطلاب» the teacher's, beside «أرصدة الطلاب».
   * `rewards.manage` and `redemptions.fulfill` are held by the teacher, the
   * owner and the super admin alone — not the assistant — so the ENTRY was
   * right and only its name was not.
   */
  { href: "/manage/rewards", label: "مكافآت الطلاب", Icon: ShopIcon, permission: P.rewardsManage },
  /*
   * Spec 011 · US1. Three entries, because they are three different people's
   * jobs — and a surface no link reaches is a surface nobody uses.
   *
   * The student's is ungated: every signed-in person may have bought a book, and
   * the page lists their own purchases plus the stores of the teachers they are
   * actually enrolled with.
   */
  { href: "/store", label: "مشترياتي", Icon: StoreIcon, audience: "learner" },
  /*
   * ⚠️ AND THE SAME SHAPE ONE ROW DOWN, WITH A SECOND FAULT: «متجري» was a
   * possessive beside the learner's «مشترياتي», AND the page it opened was
   * headed «المتجر» — a link and its own screen carrying two different names.
   * «متجر الكتب» is one name in both places, and it says which of the two shops
   * this is: the other sells nothing and takes coins.
   */
  { href: "/manage/store", label: "متجر الكتب", Icon: StoreIcon, permission: P.storeItemsManage },
  {
    href: "/manage/store/shipments",
    label: "الشحنات",
    Icon: ShipmentIcon,
    permission: P.storeShipmentsManage,
  },

  /*
   * Spec 011 · US4 — the THIRD pricing shape, and the only one that sells time.
   *
   * Two entries, because they are two different people's jobs. The student's is
   * ungated: anybody signed in may hold a subscription, and the page lists their
   * own plus what the teachers they already study with offer.
   *
   * ⚠️ «بالحصّة» AND «بعدد من الحصص» ARE NOT HERE, and their absence is correct
   * rather than an oversight: both are credit packages, priced automatically from
   * the teacher's approved rate, and they are bought from «رصيدي» below. Three
   * shapes, two screens, because two of them are one mechanism.
   */
  { href: "/plans", label: "اشتراكاتي", Icon: CreditsIcon, audience: "learner" },
  { href: "/manage/plans", label: "باقات الاشتراك", Icon: CreditsIcon, permission: P.plansManage },

  /*
   * Spec 011 · US3. Ungated: everybody has a code, and the page mints it on
   * first open — which is exactly why the endpoint is a `GET` that writes.
   */
  { href: "/referrals", label: "دعوة صديق", Icon: ReferralIcon, audience: "learner" },
  /*
   * ⚠️ THE SHARPER HALF OF THE SAME SPLIT, AND IT WAS SHOWING THE WRONG PEOPLE'S
   * NAMES — or rather, none of them. `CertificateController::index()` widens to
   * every certificate in the workspace for a holder of `certificates.view.all`,
   * so a teacher opening «الشهادات» read their STUDENTS' certificates under the
   * heading «شهاداتي», in a card layout that prints no student name at all
   * (`student_name` was in the payload the whole time, unrendered), over an
   * empty state offering «أكمل كورساً لتحصل على أولى شهاداتك».
   *
   * The label is «شهاداتي» now rather than «الشهادات»: the possessive is what
   * makes the pair legible in one glance, exactly as «واجباتي» sits beside
   * «الواجبات».
   */
  { href: "/certificates", label: "شهاداتي", Icon: CertificateIcon, audience: "learner" },
  {
    href: "/manage/certificates",
    label: "شهادات الطلاب",
    Icon: CertificateIcon,
    permission: P.certificatesViewAll,
  },
  /*
   * ⚠️ A LEARNER SCREEN SINCE 2026-09-03, AND IT WAS THE LAST DUAL-MEANING ONE.
   * It used to carry the teacher's approvals queue as well as the student's own
   * receipts, so it could not be tagged without taking the teacher's only door
   * to approving a transfer. `payments.approve` has moved to the platform's
   * finance officer and the decision is made in `/admin` — the payee cannot be
   * the witness that their own money arrived (spec 014) — so this screen is the
   * buyer's alone now and the tag costs the teacher nothing.
   */
  { href: "/orders", label: "الطلبات", Icon: OrdersIcon, audience: "learner" },
  // The student's credits, counted in sessions and never in money. Separate
  // from /orders, which is one payment at a time: this is the standing balance
  // those payments produce, per course.
  { href: "/billing", label: "رصيدي", Icon: CreditsIcon, audience: "learner" },
  /*
   * Spec 010 · US2. No permission: everyone signed in has a side of a private
   * conversation — the student writes to their teacher, the teacher and whoever
   * they authorised answer. `ListConversations` returns an empty list to anyone
   * with neither, which is a screen that says so rather than a link that 403s.
   */
  { href: "/messages", label: "الرسائل", Icon: MessagesIcon },
  { href: "/notifications", label: "الإشعارات", Icon: BellIcon },
  { href: "/family", label: "المرتبطون", Icon: FamilyIcon },
  /*
   * Spec 013. ⚠️ ITS OWN ENTRY, and NOT a tab inside settings.
   *
   * A right nobody can find is a right nobody exercises — and the whole phase
   * rests on a person being able to see what is collected about their child and
   * withdraw the optional part of it. It sits beside «المرتبطون» because that is
   * where a guardian already goes to manage what concerns their children.
   *
   * ⚠️ AND IT IS `/settings/privacy`, NOT `/privacy` — WHICH IS NOT A PREFERENCE.
   * `(public)/privacy` already owns that path: it is the policy text a visitor
   * reads before signing up, linked from every footer and from both signup forms.
   * Route groups produce NO URL segment, so two pages under different groups
   * resolving to one path is not two screens kept apart by their layouts — it is
   * `You cannot have two parallel pages that resolve to the same path`, and Next
   * refuses to serve the WHOLE APP: every route answers 500, not just these two.
   * It shipped that way for a session, and neither `tsc --noEmit` nor vitest can
   * see it, because neither builds routes.
   */
  { href: "/settings/privacy", label: "خصوصيّتي", Icon: ShieldIcon },
];

const adminNav: NavItem[] = [
  // How this academy collects. Under admin, not beside /billing: that one is the
  // student's own balance, this one is the policy that produces it.
  { href: "/manage/billing/settings", label: "إعدادات الفوترة", Icon: CreditsIcon, permission: P.billingSettings },
  // Who has sessions left and who has stopped. Beside the policy rather than
  // under /manage/sessions, because it answers a money question about students
  // — in credits only, never in money.
  { href: "/manage/billing/students", label: "أرصدة الطلاب", Icon: CreditsIcon, permission: P.billingBalanceView },
  // Exam season, when nothing is deferred. Its own entry rather than a switch on
  // the settings screen: it is a period on a calendar with a start and an end,
  // not a preference, and it expires by itself.
  { href: "/manage/billing/exam-mode", label: "وضع الامتحانات", Icon: CreditsIcon, permission: P.billingExamMode },
  /*
   * أماكن عملي — وارثةُ «مساحات العمل» (مواصفة ٠٢٥ · FR-014).
   *
   * ⚠️ لا `permission` بعدَ اليوم، والعددُ هو المسند. كان الحارسُ `members.view`
   * تقريبًا لسؤال «هل أنت موظّفٌ في مكانٍ ما؟» — تقريبٌ لأنّ الجواب الحقيقيّ صار
   * محمولًا في الحمولة نفسها: `user.workspaces`. صفرٌ يُسقِط المدخلَ كلَّه (طالبٌ
   * أو وليُّ أمر)، وواحدٌ يعرض **اسمَ المكان نفسِه** لا صيغةَ مفردٍ من جمع
   * (FR-014أ · FR-025)، وأكثرُ يعرض «أماكن عملي». انظر `placeLabelled` أدناه.
   *
   * ⚠️ ولا زرَّ إنشاءٍ بعدَ اليوم: `/workspaces/new` حُذفت، و`POST /workspaces`
   * يُجيب `403` لكلّ من لا يحمل `workspaces.create` — والبابُ يُغلَق على الخادم لا
   * بإخفاء زرّ. `linkOnly` تبقى لأنّ المسار يبقى مفتوحًا، والصفحةُ تقول لمن لا
   * مكانَ له جملةً واحدةً وتدلّه على «دراستي».
   */
  { href: "/workspaces", label: "أماكن عملي", Icon: WorkspaceIcon, linkOnly: true },
  { href: "/members", label: "الأعضاء", Icon: MembersIcon, permission: P.membersView },
  /*
   * Spec 010 · US1 — the teacher's team, beside the member list it rides on.
   *
   * ⚠️ A SEPARATE ENTRY FROM «الأعضاء», not a tab inside it. That screen answers
   * «who is in this workspace»; this one answers «what ground does each assistant
   * work on», which is a different question with a different reader — and folding
   * it in is how the confinement becomes a setting nobody finds.
   */
  { href: "/manage/assistants", label: "فريق المساعدين", Icon: MembersIcon, permission: P.rolesManage },
  /*
   * Spec 013 · US6 — a teacher asking to wind down.
   *
   * ⚠️ AND WITHOUT THIS ENTRY THE ENDPOINTS ARE UNREACHABLE. The whole flow shipped
   * behind a platform permission while the user story reads "a teacher asks to
   * leave" — a page nothing links to is that same defect wearing a URL.
   *
   * ⚠️ IT WAS GATED ON NOTHING UNTIL 2026-08-27, on the reasoning that the SERVER
   * answers to `workspaces.owner_user_id` and a permission here would be a
   * second, weaker copy of it. True of the ASSISTANT it was written about, and
   * false of everybody else: an ungated entry is in every STUDENT's sidebar, and
   * the page it opens says «ما يحدث لطلابك ولمحتواك ولمستحقّاتك» to somebody who
   * has no students — under a 403 banner, beside a live «اطلبِ الخروج». This
   * file's own rule two entries below settles it: a menu item nobody may open is
   * worse than a missing one.
   *
   * `settlement.statement.view` is the nearest predicate the client already
   * holds — it is on `$teacher` and deliberately NOT on `$assistantTeacher`, so
   * it hides the entry from exactly the two readers who cannot use it while
   * staying strictly wider than the ownership rule the server enforces.
   */
  { href: "/teaching/offboarding", label: "إنهاء النشاط", Icon: ShieldIcon, permission: P.settlementStatement },
  { href: "/settings", label: "الإعدادات", Icon: SettingsIcon },
];

/*
 * ⚠️ ITS OWN ARRAY, SHOWN TO THE SUPER ADMIN ALONE — and that is not decoration.
 * Every entry above is a workspace question a teacher may legitimately ask;
 * `billing.collection.view` is held by no tenant role at all, so putting the
 * reconciliation beside "إعدادات الفوترة" would show every teacher on the
 * platform a link that answers 403. A menu item nobody may open is worse than a
 * missing one: it reads as something broken rather than something private.
 *
 * The server is still the guard — this array only decides what is offered.
 */
const platformNav: NavItem[] = [
  // What the hourly payment sweep found: money that settled without telling us,
  // and what it could not resolve on its own.
  { href: "/manage/payments/reconciliation", label: "تسوية المدفوعات", Icon: CreditsIcon, permission: P.billingCollection },
  /*
   * ⛔ ITS TWIN, AND THE TWIN HAD NO LINK AT ALL. `ReconcileCreditBalancesJob`
   * has run every night since spec 014 writing `credit_reconciliation_runs`, and
   * the only reader of that table was a test — a nightly check on whether the
   * ledger still adds up, with nowhere for anybody to notice that it had stopped.
   *
   * Same permission as the line above, because it is the same job wearing a
   * different table. Its route asked `billing.pricing.manage` until 2026-09-05:
   * a READ of the platform's ledger behind the door for EDITING the platform's
   * cut, so the officer who opens the sweep above every morning was refused this
   * one.
   */
  { href: "/manage/billing/reconciliation", label: "مطابقة الأرصدة", Icon: CreditsIcon, permission: P.billingCollection },
  // Every financial decision and the terminal it came from. Beside the
  // reconciliation rather than under it: one asks what the machine could not
  // settle, the other asks what people decided — and an auditor opens the second
  // when the first has already been dealt with.
  { href: "/manage/payments/audit", label: "سجلّ التدقيق المالي", Icon: OrdersIcon, permission: P.billingAudit },
  // Spec 011 · FR-045. Gated on the PLATFORM analytics permission — the same
  // door the API checks, so the link and the screen answer the same question.
  {
    href: "/reports/subscriptions",
    label: "تقارير المنصّة",
    Icon: ProgressIcon,
    permission: P.analyticsCrossTeacher,
  },
  // What came in, over a period. Beside the two above rather than under the
  // teacher's billing screens: this is the platform's collection across every
  // workspace, and a teacher holding every tenant permission there is cannot
  // open it.
  { href: "/manage/payments/collection", label: "سجلّ التحصيل", Icon: CreditsIcon, permission: P.billingCollection },
  /*
   * Spec 013 — the data-protection officer's queue. PLATFORM, not admin: an
   * erasure destroys a student's record across every teacher they study with, so
   * no tenant role holds the permission and a teacher holding all of them cannot
   * open this.
   *
   * ⚠️ AND WITHOUT THIS ENTRY THE ENDPOINTS ARE UNREACHABLE. `store` deliberately
   * does not dispatch an erasure — FR-019's announced execution period is a person
   * pressing a button here — so a queue with no way in is every erasure request
   * ever made sitting `pending` for ever, with nothing failing to say so.
   */
  { href: "/manage/compliance", label: "طلبات حقوق البيانات", Icon: ShieldIcon, permission: P.complianceRequestsExecute },
  // The exits waiting on money or on a date. Its own permission, held by no
  // tenant role: completing one revokes a teacher's access and fixes their
  // recordings' retention across every student who studied with them.
  { href: "/manage/compliance/offboardings", label: "خروج المدرّسين", Icon: ShieldIcon, permission: P.complianceOffboardingExecute },
];

const allNav = [...mainNav, ...adminNav, ...platformNav];

/** Whether the reader last chose the narrow rail. Per browser, per person. */
const NAV_COLLAPSED_KEY = "nav:collapsed";


export default function ShellLayout({ children }: { children: ReactNode }) {
  const { user, loading, logout } = useAuth();
  // The product's name, for the mark's accessible label and its tooltip. The
  // logo itself carries no text, so this is the only thing announced.
  const platform = usePlatformName();
  const router = useRouter();
  const pathname = usePathname();

  // Below `md` the 16rem sidebar is wider than half a phone and sits over the
  // page, so it is a drawer there and permanent from `md` up. Without this the
  // panel is not merely cramped on a phone — the nav intercepts every click
  // meant for the content behind it.
  const [navOpen, setNavOpen] = useState(false);
  const [pendingGrading, setPendingGrading] = useState(0);

  /*
    ⚠️ THE RAIL IS A SECOND STATE, NOT THE DRAWER AT ANOTHER WIDTH. On a phone the
    nav sits OVER the page and the question is «is it open»; from `md` up it sits
    BESIDE the page and the question is «how much room does it take». One flag for
    both would mean closing the drawer on a phone also collapsed the desktop rail
    the next time the reader opened a laptop — two answers to two different
    questions, stored in one box.

    Read from `localStorage` after mount, never during render: the server has no
    such thing, and reading it in the initial state is a hydration mismatch that
    React resolves by silently keeping the server's answer.
  */
  const [collapsed, setCollapsed] = useState(false);

  useEffect(() => {
    try {
      setCollapsed(window.localStorage.getItem(NAV_COLLAPSED_KEY) === "1");
    } catch {
      // A private window, or site data blocked. The default is the expanded nav,
      // which is the state that hides nothing.
    }
  }, []);

  const toggleCollapsed = () => {
    setCollapsed((current) => {
      const next = !current;

      try {
        window.localStorage.setItem(NAV_COLLAPSED_KEY, next ? "1" : "0");
      } catch {
        // Not being able to remember the choice must not stop them making it.
      }

      return next;
    });
  };

  useEffect(() => {
    if (!loading && !user) {
      router.push("/login");
    }
  }, [user, loading, router]);

  // Closed on every navigation. The drawer sits above the page on a phone, so
  // one left open covers the screen the link just went to.
  useEffect(() => setNavOpen(false), [pathname]);

  /*
   * How many papers are waiting on this teacher.
   *
   * ⚠️ RE-READ ON EVERY NAVIGATION, NOT POLLED. The bell polls because an
   * eviction has to reach a page nobody is touching; a grading count does not —
   * the teacher who just marked a paper is the one whose number changed, and
   * they navigate immediately afterwards. A second interval on every panel page
   * would be a request a minute, for ever, for a number that moves twice a day.
   */
  useEffect(() => {
    if (!can(user, P.gradingPerform)) return;

    grading
      .queue(1, 1)
      .then((response) => setPendingGrading(response.meta?.total ?? 0))
      // Silent: a failed count must never take the sidebar down with it.
      .catch(() => setPendingGrading(0));
  }, [user, pathname]);

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <p className="text-ink-muted">جارٍ التحميل…</p>
      </div>
    );
  }

  if (!user) return null;

  /*
   * ⚠️ TWO GATES, AND THEY ANSWER DIFFERENT QUESTIONS IN DIFFERENT DIRECTIONS.
   * `can()` asks «may you», which hides the teacher's tools from the student.
   * `learns` asks «is this yours», which hides the student's screens from the
   * teacher — and there is no permission that could have done it, because the
   * student holds none to check.
   *
   * ⚠️ IT IS A FILTER ON WHAT IS OFFERED, NEVER A GUARD. The routes stay open,
   * exactly as this file's own rule has always had it: the server decides. A
   * teacher who types `/shop` still gets the page, and it is empty — which is
   * the honest answer, not a refusal.
   */
  const learns = isLearner(user);

  const allowed = (items: NavItem[]) =>
    items.filter((item) => can(user, item.permission) && (item.audience !== "learner" || learns));

  /*
   * أماكن العمل: العدد يقرّر اللافتة (مواصفة ٠٢٥ · FR-014 · FR-014أ · FR-025).
   *
   * ⚠️ ثلاثة أجوبة لا اثنان، والوسطُ هو المقصود بالبند. «مكان عملي» صيغةُ مفردٍ
   * من جمع، وهي تُبقي في ذهن القارئ أنّ ثمّة أماكنَ أخرى — وهو بالضبط ما تُلغيه
   * هذه المواصفة. فحين يكون واحدًا يُعرَض **اسمُه هو**، المشتقُّ من اسم المدرّس.
   *
   * ⚠️ ويُقرأ من `useAuth()` بلا جلبٍ ثانٍ: الحقل يصل مع `me()` أصلًا.
   */
  const places = user?.workspaces ?? [];

  const placeLabelled = (items: NavItem[]) =>
    items.flatMap((item) => {
      if (item.href !== "/workspaces") return [item];
      if (places.length === 0) return [];

      return [{ ...item, label: places.length === 1 ? places[0].name : item.label }];
    });

  const refused = refusedBy(allNav, pathname, user);

  const renderItem = ({ href, label, Icon, badge }: NavItem) => {
    const active = pathname === href || pathname.startsWith(href + "/");
    const showBadge = badge === "grading" && pendingGrading > 0;

    return (
      <Link
        key={href}
        href={href}
        aria-current={active ? "page" : undefined}
        /*
          ⚠️ `title` ONLY WHEN THE LABEL IS HIDDEN. A tooltip repeating text that
          is already on screen is a second copy for a screen reader to announce;
          on the rail it is the only way to learn what the icon means with a
          mouse. The accessible name comes from the `sr-only` span below either
          way, so the link is never an unlabelled glyph.
        */
        title={collapsed ? label : undefined}
        /*
          ⚠️ EVERY COLLAPSED STYLE IS AN `md:` VARIANT, WITHOUT EXCEPTION. The rail
          is a desktop state and the drawer is not — but `collapsed` is remembered
          per BROWSER, so an un-gated class means the reader who narrowed the nav
          on their laptop opens the drawer on their phone and finds a full-width
          panel of unlabelled icons. That is the report this whole change answers,
          re-manufactured one breakpoint lower.
        */
        className={`mb-1 flex items-center gap-3 rounded-lg py-2.5 text-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary px-3 ${
          collapsed ? "md:justify-center md:px-2" : ""
        } ${
          active
            ? "bg-primary font-semibold text-white"
            : "text-ink hover:bg-primary-soft hover:text-primary-ink"
        }`}
      >
        <span className="relative shrink-0">
          <Icon className="h-5 w-5" />

          {/*
            ⚠️ ON THE RAIL THE COUNT BECOMES A DOT ON THE ICON, and it must not
            disappear. The number is the whole reason «لوحة التصحيح» earns a place
            in this list — a paper waiting to be marked is a student waiting for a
            result — so collapsing the nav may take the digits and may not take
            the fact that something is waiting.
          */}
          {showBadge && collapsed && (
            <span
              aria-hidden
              className="absolute -end-0.5 -top-0.5 hidden h-2 w-2 rounded-full bg-accent ring-2 ring-surface-raised md:block"
            />
          )}
        </span>

        <span className={`flex-1 ${collapsed ? "md:sr-only" : ""}`}>{label}</span>

        {showBadge && (
          <span
            className={`rounded-full px-2 py-0.5 text-xs font-semibold ${collapsed ? "md:hidden" : ""} ${
              active ? "bg-white/20 text-white" : TONE_CLASSES.warning
            }`}
          >
            <bdi>{pendingGrading}</bdi>
          </span>
        )}

        {/* On the rail the visible count is gone; this keeps it in the accessible
            name rather than leaving «لوحة التصحيح» with a silent dot. Harmless at
            full width, where the badge above already says it visibly. */}
        {showBadge && collapsed && <span className="sr-only">{`${pendingGrading} بانتظار التصحيح`}</span>}
      </Link>
    );
  };

  return (
    <div className="flex min-h-screen">
      {/* Renders nothing. Here rather than in the root layout because the worker
          exists for the signed-in application — a visitor reading the
          marketplace has nothing to cache and nothing to be pushed. */}
      <ServiceWorkerRegistrar />
      {/* Logical `start-0` / `ms-64`, not `left-0` / `ml-64`: in RTL the sidebar
          belongs on the right, and physical offsets put it on the wrong edge
          while leaving a 16rem gutter on the other one (FR-015). */}
      <aside
        id="panel-nav"
        /* ⚠️ A FLEX COLUMN, and the footer is a CHILD of it — not an
           `absolute bottom-0` panel over a scrolling list. Positioned, it sat
           on top of the last nav entries on a short viewport and INTERCEPTED
           THEIR CLICKS: the admin links were visible, focusable and unreachable
           on a phone, which is a link that does not exist wearing the costume of
           one. Found by e2e on the 360×780 project. */
        /*
          ⚠️ THE DRAWER IS ALWAYS FULL WIDTH; ONLY THE DESKTOP RAIL NARROWS. A
          16rem panel is wider than half a phone and sits OVER the page there, so
          a «space-saving» 4rem version of it would save space nobody was using
          and hide the labels on the one screen with room for them under a finger.
          The rail is a `md:` question, and the drawer is not.

          ⚠️ AND `transition-[width]`, NOT `transition-all`. The panel is
          `fixed inset-y-0` with a scrolling child; animating every property makes
          the browser re-layout that child on each frame of the collapse.
        */
        className={`fixed inset-y-0 start-0 z-20 ${navOpen ? "flex" : "hidden"} w-64 flex-col border-e border-line bg-surface-raised transition-[width] duration-200 md:flex ${collapsed ? "md:w-16" : "md:w-64"}`}
      >
        <div className={`flex h-16 shrink-0 items-center px-6 ${collapsed ? "md:justify-center md:px-0" : ""}`}>
          <Link
            href="/dashboard"
            title={platform}
            /*
              ⚠️ THE ACCESSIBLE NAME IS SET AT EVERY WIDTH NOW, NOT ONLY ON THE
              RAIL. The mark is a masked background on an empty span, so there is
              no text node left for a screen reader to read — without this the
              link would be announced as «رابط» and nothing else. It used to be
              conditional because the expanded state carried the word itself.
            */
            aria-label={platform}
            className="flex items-center rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {/*
              ⚠️ THE SAME MARK THE PUBLIC HEADER AND `/admin` PAINT, from one
              asset. `.wordmark` is a `mask-image` over `background-color`, so the
              maroon comes from the token on light and the warm white on dark —
              one file for both themes, and nothing to keep in step with a second
              export. Its `aspect-ratio` is 941/789, so at `h-9` it is ~43px wide
              and still fits the 4rem rail: the old first-letter fallback for the
              collapsed state has nothing left to do.
            */}
            <BrandMarkDecorative size={collapsed ? "sm" : "md"} />
          </Link>
        </div>
        {/* The one thing that scrolls. Everything else keeps its height, so a
            long nav never pushes the account panel off the screen. */}
        <nav aria-label="التنقّل الرئيسي" className={`flex-1 overflow-y-auto py-4 px-3 ${collapsed ? "md:px-2" : ""}`}>
          {placeLabelled(allowed(mainNav)).map(renderItem)}
          {/* ⚠️ The heading is hidden with its list, not left standing over an
              empty box. A section title with nothing under it reads as content
              that failed to load. */}
          {allowed(adminNav).length > 0 && (
            <div className="mb-1 mt-4 border-t border-line pt-4">
              {/* ⚠️ Hidden on the rail rather than truncated. «الإدارة» clipped to
                  two letters over a column of icons is noise where the border
                  above it already says «a new group starts here». */}
              <p className={`mb-2 px-3 text-xs font-semibold tracking-wide text-ink-muted ${collapsed ? "md:sr-only" : ""}`}>
                الإدارة
              </p>
              {allowed(adminNav).map(renderItem)}
            </div>
          )}
          {/* ⚠️ Per item, no longer on `is_super_admin`. That flag had the bug
              running the other way too: a platform finance officer holds
              `billing.audit.view` through `platform_staff` and never saw the
              link, because they are not a super admin. */}
          {allowed(platformNav).length > 0 && (
            <div className="mb-1 mt-4 border-t border-line pt-4">
              <p className={`mb-2 px-3 text-xs font-semibold tracking-wide text-ink-muted ${collapsed ? "md:sr-only" : ""}`}>
                المنصّة
              </p>
              {allowed(platformNav).map(renderItem)}
            </div>
          )}
        </nav>
        <div className={`shrink-0 border-t border-line bg-surface-raised p-4 ${collapsed ? "md:p-2" : ""}`}>
          <div className={`mb-3 flex items-center gap-3 ${collapsed ? "md:justify-center" : ""}`}>
            <div
              aria-hidden="true"
              title={collapsed ? user.name : undefined}
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-medium text-white"
            >
              {user.first_name.charAt(0)}
            </div>

            {/* ⚠️ REMOVED FROM THE RAIL RATHER THAN TRUNCATED. An address cut to
                «ahm…» in a 4rem column is not a shorter address, it is an
                unreadable one — and the initial above already says whose account
                this is. */}
            <div className={`min-w-0 flex-1 ${collapsed ? "md:hidden" : ""}`}>
              <p className="truncate text-sm font-medium text-ink">{user.name}</p>
              <p className="truncate text-xs text-ink-muted">
                {/* Latin inside Arabic: <bdi> keeps the address from being
                    reordered around the surrounding RTL run (FR-005). */}
                <bdi>{user.email}</bdi>
              </p>
            </div>
          </div>
          {/*
            ⚠️ THE WAY BACK TO THE PUBLIC SITE, AND THERE WAS NONE. Once signed
            in, every link in this shell points further INTO the panel — so the
            marketplace, the teacher pages and the policies the footer links from
            every public page were reachable only by editing the address bar. The
            logo above goes to `/dashboard` (a signed-in person's home is their
            own screen), which is exactly why the home page needs a link of its
            own rather than borrowing that one.

            Placed in the account block rather than in `mainNav`: everything in
            that list is a screen of this product, and a permission-filtered list
            is the wrong place for a link every account holds.
          */}
          <Link
            href="/"
            title={collapsed ? "الصفحة الرئيسية" : undefined}
            className="mb-2 flex w-full items-center justify-center gap-2 rounded-lg border border-line py-2 text-sm text-ink transition-colors hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            <SiteIcon />
            <span className={collapsed ? "md:sr-only" : ""}>الصفحة الرئيسية</span>
          </Link>
          <button
            type="button"
            onClick={() => {
              logout();
              router.push("/login");
            }}
            title={collapsed ? "تسجيل الخروج" : undefined}
            className="flex w-full items-center justify-center gap-2 rounded-lg border border-line py-2 text-sm text-ink transition-colors hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            <LogoutIcon />
            <span className={collapsed ? "md:sr-only" : ""}>تسجيل الخروج</span>
          </button>
        </div>
      </aside>

      {/* The offset tracks the panel's width, and animates with it — a content
          column that jumps to its new margin while the nav is still sliding is
          two elements disagreeing about where the edge is. */}
      <div className={`flex-1 transition-[margin] duration-200 ${collapsed ? "md:ms-16" : "md:ms-64"}`}>
        {/*
          ⚠️ THE BAR IS FULL-BLEED AND ITS CONTENTS ARE NOT. The rule under it has
          to reach both edges — a border that stops short reads as a card — while
          the title has to start where the content below it starts. Capped only on
          the inner row, with the same `max-w-7xl` the main region uses, or the
          heading sits at the viewport edge on a wide monitor while the page it
          names floats centred underneath.
        */}
        <header className="sticky top-0 z-10 border-b border-line bg-surface-raised">
          <div className="mx-auto flex h-16 w-full max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
          <div className="flex min-w-0 items-center gap-3">
            {/*
              ⚠️ TWO BUTTONS, ONE POSITION, AND THAT IS THE FIX. There was a
              single `md:hidden` hamburger, so from `md` up the nav had NO control
              at all: sixteen rems of the window were spent on it on every screen,
              on every page, with no way to give them back. The two answer
              different questions — below `md` the panel is a drawer OVER the page
              («is it open»), from `md` up it is a column BESIDE the page («how
              wide»). One button doing both would need its icon, its label and its
              `aria-expanded` to mean two things at two widths.

              The desktop one is not `aria-expanded`: the nav is never hidden
              there, only narrowed, and announcing «collapsed» as «closed» tells a
              screen-reader user the links are gone when every one of them is
              still in the tree.
            */}
            <button
              type="button"
              onClick={() => setNavOpen((open) => !open)}
              aria-expanded={navOpen}
              aria-controls="panel-nav"
              aria-label={navOpen ? "إغلاق التنقّل" : "فتح التنقّل"}
              /* ⚠️ `p-2.5` MAKES IT 44px, AND IT WAS 32. A 24px icon under `p-1`
                 is a 32×32 target — measured on a 390px viewport — which is under
                 every published minimum for a finger and is the only way into the
                 navigation on a phone. The desktop twin below keeps the same
                 padding for alignment; a pointer needs far less, but two buttons
                 of different heights in one row is a wobble. */
              className="-m-1 rounded-lg p-2.5 text-ink transition-colors hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:hidden"
            >
              {navOpen ? <CloseIcon /> : <MenuIcon />}
            </button>

            <button
              type="button"
              onClick={toggleCollapsed}
              aria-controls="panel-nav"
              aria-label={collapsed ? "توسيع قائمة التنقّل" : "تصغير قائمة التنقّل"}
              title={collapsed ? "توسيع القائمة" : "تصغير القائمة"}
              className="-m-1 hidden rounded-lg p-2.5 text-ink transition-colors hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:block"
            >
              {/*
                ⚠️ DIRECTION LIVES IN THE ICON'S NAME, NEVER IN A CSS FLIP. In RTL
                the panel is on the RIGHT, so «collapse» points at the start edge
                and «expand» away from it — `ChevronStart`/`ChevronEnd` already
                carry that and a `scale-x-[-1]` on a chevron would point the wrong
                way in exactly one of the two directions the product supports.
              */}
              {/* Expanded: the press shrinks the panel toward the start edge it sits
                  on, so the arrow points there (right, in RTL). Collapsed: the
                  press grows it toward the end, so the arrow points away. */}
              {collapsed ? <ChevronEndIcon /> : <ChevronStartIcon />}
            </button>

            <h1 className="truncate text-lg font-semibold text-ink">
              {allNav.find((i) => pathname.startsWith(i.href))?.label ?? "لوحة التحكم"}
            </h1>
          </div>
          <div className="flex items-center gap-1">
            <NotificationBell />
            <ThemeToggle />
          </div>
          </div>
        </header>
        {/*
          ⚠️ THE READING WIDTH IS CAPPED HERE, IN ONE PLACE, AND IT WAS CAPPED
          NOWHERE. `<main>` carried padding and no maximum, so every unbounded
          page — the dashboard, the timetable, the lists added since — stretched
          to whatever the monitor was: a four-column stat grid across 2560px, and
          table rows whose eye has to travel a metre from the label to the value.
          Twenty-nine pages had each set their own `max-w-2xl`/`3xl` to escape it,
          which is the same fix written twenty-nine times and missing from the
          thirtieth.
          |
          | One container solves all of them and composes with those: a page that
          | wants a narrower column keeps its own `mx-auto max-w-2xl` and simply
          | centres inside this one. `w-full` so the cap never becomes a floor on a
          | phone, and the padding steps down on small screens — 24px of gutter on
          | a 360px viewport is 13% of it spent on nothing.
        */}
        <main id="main" className="mx-auto w-full max-w-7xl p-4 sm:p-6">
          {refused ? (
            // No heading of the screen's own above it: «أرصدة الطلاب» over a
            // refusal still tells the reader whose money this page is about.
            <Alert tone="warning" title="هذه الصفحة ليست لك">
              حسابك لا يملك صلاحية فتح هذه الصفحة.
            </Alert>
          ) : (
            children
          )}
        </main>
      </div>
    </div>
  );
}
