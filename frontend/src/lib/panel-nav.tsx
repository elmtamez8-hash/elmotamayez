/**
 * تعريفُ تنقّلِ اللوحةِ — في مكانٍ واحدٍ يقرؤُه كلُّ من يعرضُ رابطاً منه.
 *
 * ⚠️ **أُخرِجَ من `(app)/(shell)/layout.tsx` يومَ احتاجَتْه ترويسةٌ ثانية.** كانَ
 * يعيشُ داخلَ ذلك الملفِّ وحدَه، فلمّا طُلِبَ أن تظهرَ قائمةُ الحسابِ **في كلِّ
 * الصفحاتِ لا في اللوحةِ فقط** (بلاغُ ٢٠٢٦-٠٩-٠٦) لم يكنْ أمامَ `SiteHeader`
 * العامّةِ إلّا نسخةٌ ثانيةٌ من البنودِ وشرطِ إظهارِها — وهو عطبُ «تهجئتَينِ لسؤالٍ
 * واحد» الذي دفعَ ثمنَه هذا المستودعُ في `BookingEligibility` و
 * `ListLeaderboardScopes`: منتقٍ يُجمَّعُ بجانبِ الحارسِ يعرضُ ما يرفضُه البابُ
 * ويُخفي ما يسمحُ به.
 *
 * لا حالةَ هنا ولا مكوّن: بياناتٌ ودالّتا ترشيحٍ خالصتان.
 */

import type { ComponentType } from "react";

import {
  AssignmentIcon,
  BellIcon,
  CertificateIcon,
  ChevronEndIcon,
  ChevronStartIcon,
  CloseIcon,
  CoursesIcon,
  DocumentIcon,
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
import { P, can } from "@/lib/permissions";
import { dashboardAudience, type DashboardAudience } from "@/lib/dashboard-audience";
import type { User } from "@/lib/types";

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
export type NavItem = {
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
   * Who this screen belongs to. Absent means everybody who passes the
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
   *
   * ⚠️ **AND IT WAS THE BARE WORD `"learner"`, WHICH PUT FIFTEEN OF THE
   * STUDENT'S OWN SCREENS IN A GUARDIAN'S SIDEBAR.** The filter read
   * {@link isLearner}, which answers YES for a parent — deliberately, since two
   * of these entries (`/reviews`, `/report-cards`) really are read by both, each
   * with a child picker written into the page. The other fifteen are not:
   * «تعلّمي», «شهاداتي», «رصيدي», «واجباتي», «دفتر أخطائي» are the STUDENT'S
   * enrolments, certificates, balance and homework, and a guardian holds none of
   * them — every one of those screens reads the caller's own rows and renders
   * empty. Reported from a real guardian account on 2026-09-07. One boolean
   * cannot express three audiences, so the tag names them.
   */
  audience?: DashboardAudience[];
  /**
   * اسمٌ آخرُ للشاشةِ نفسِها عندَ جمهورٍ آخر. غيابُه يعني أنّ `label` يصلحُ للكلّ.
   *
   * ⚠️ **بندٌ واحدٌ يستعملُه، وهو بندٌ كانت صفحتُه تعرفُ ما لا يعرفُه الشريط.**
   * `‎/reviews` يكتبُ عنوانَه منذُ ٠١٠ بفرعٍ صريح — «التقييمات الدورية» لوليِّ
   * الأمرِ و«تقييماتي الدورية» للطالب — بينما الشريطُ الجانبيُّ يقولُ «تقييماتي»
   * لوليِّ أمرٍ لا يُقيَّم. بلاغُ ٢٠٢٦-٠٩-٠٧.
   *
   * ⚠️ **والصفحةُ تقرأُ هذا الحقلَ الآن ولا تُهجِّي الاسمَ مرّةً ثانية**
   * ({@link navLabel}): اسمٌ مكتوبٌ في موضعَينِ يفترقُ عندَ أوّلِ إعادةِ صياغة،
   * وهو العطبُ الذي دفعَ ثمنَه هذا المستودعُ في `BookingEligibility` و
   * `ListLeaderboardScopes` — وهنا كانَ نصفُه مكتوباً بالفعل.
   */
  labels?: Partial<Record<DashboardAudience, string>>;
};

export const mainNav: NavItem[] = [
  { href: "/dashboard", label: "لوحة التحكم", Icon: HomeIcon },
  // /courses is the public marketplace listing; course management lives under
  // /manage so the two do not resolve to the same route.
  { href: "/manage/courses", label: "الكورسات", Icon: CoursesIcon, permission: P.coursesUpdate },
  // /schedule is the student's own timetable across every teacher;
  // /manage/sessions is the teacher's calendar. Two screens, two audiences —
  // collapsing them into one route would make each show the other half nothing.
  { href: "/schedule", label: "جدولي", Icon: ScheduleIcon, audience: ["student"] },
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
  { href: "/enrollments", label: "تعلّمي", Icon: LearningIcon, audience: ["student"] },
  // ⚠️ The student's own notebook, and it needs its own entry. It is derived
  // from answers rather than authored, so nothing in the product would ever link
  // to it — a screen reachable only by typing its address is a screen nobody
  // opens.
  { href: "/mistakes", label: "دفتر أخطائي", Icon: MistakesIcon, audience: ["student"] },
  // Building your own paper is a different act from reading what you got wrong:
  // one starts from the bank and the other from your own history. Two entries,
  // because a student who wants to revise a topic they have never been tested on
  // would never look for it inside a notebook of mistakes.
  { href: "/practice", label: "درّب نفسك", Icon: PracticeIcon, audience: ["student"] },
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
  { href: "/exams", label: "الاختبارات", Icon: ExamIcon, audience: ["student"] },
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
  { href: "/assignments", label: "واجباتي", Icon: AssignmentIcon, audience: ["student"] },
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
  { href: "/progress", label: "تقدّمي", Icon: ProgressIcon, audience: ["student"] },
  /*
   * Spec 010 · US4. No permission: every signed-in person has a side of this —
   * a student reads their own, a guardian reads a child's through the same
   * screen, and a teacher simply sees an empty list. `manage/students/{uuid}/reviews`
   * is the writing side and is reached from the class register, where the
   * teacher already knows whose row they clicked.
   */
  {
    href: "/reviews",
    label: "تقييماتي الدورية",
    Icon: ProgressIcon,
    audience: ["student", "guardian"],
    labels: { guardian: "التقييمات الدورية" },
  },
  /*
   * Spec 010 · US5. The same reasoning as the line above — a student reads their
   * own cards, a guardian reads a child's through the same screen, and a teacher
   * sees an empty list because the card is not theirs to hold. Their side is the
   * weightings below, and their own segment on the student's page.
   */
  { href: "/report-cards", label: "كشف التقديرات", Icon: ProgressIcon, audience: ["student", "guardian"] },
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
  /*
   * ⛔ المدوّنةُ كانت قدرةً بلا باب. `cms.*` في دورَي المدرّسِ والمساعدِ منذُ
   * ٠١١ — بموديلٍ وسياسةٍ وتوليدِ رابطٍ عربيٍّ وإعلانِ IndexNow وحقولِ سيو
   * وخريطةِ موقعٍ ومدوّنةٍ عامّةٍ كلُّها مبنيّةٌ حولَ ما يكتبُه المدرّس — ولم يكنْ
   * في المنتَجِ شاشةٌ واحدةٌ تكتبُ مقالاً، ولا مسارٌ يستدعيه ملفٌّ في الواجهة.
   *
   * ⚠️ والبوّابةُ `cmsUpdate` لا `cmsView`: يحملُ كلُّ طالبٍ الثانيةَ بالمصفوفة،
   * فبوّابةٌ عليها تُظهِرُ لكلِّ طالبٍ لافتةً إلى شاشةِ تأليفٍ ليست له.
   */
  {
    href: "/manage/blog",
    label: "المدوّنة",
    Icon: DocumentIcon,
    permission: P.cmsUpdate,
  },
  { href: "/leaderboard", label: "لوحة الصدارة", Icon: LeaderboardIcon, audience: ["student"] },
  { href: "/shop", label: "متجر المكافآت", Icon: ShopIcon, audience: ["student"] },
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
  { href: "/store", label: "مشترياتي", Icon: StoreIcon, audience: ["student"] },
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
  { href: "/plans", label: "اشتراكاتي", Icon: CreditsIcon, audience: ["student"] },
  { href: "/manage/plans", label: "باقات الاشتراك", Icon: CreditsIcon, permission: P.plansManage },

  /*
   * Spec 011 · US3. Ungated: everybody has a code, and the page mints it on
   * first open — which is exactly why the endpoint is a `GET` that writes.
   */
  { href: "/referrals", label: "دعوة صديق", Icon: ReferralIcon, audience: ["student"] },
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
  { href: "/certificates", label: "شهاداتي", Icon: CertificateIcon, audience: ["student"] },
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
  /*
   * ⚠️ AND THE GUARDIAN, ADDED BY 030 — a regression 029 shipped. A guardian may
   * buy for a child they hold `payments` over, and the subscribe screen sends them
   * here in a success banner; with `["student"]` alone that banner was their ONLY
   * route to the order, and `/orders` is the one surface for replacing a rejected
   * receipt. Navigate away once and the thing they paid for was unreachable.
   */
  { href: "/orders", label: "الطلبات", Icon: OrdersIcon, audience: ["student", "guardian"] },
  // The student's credits, counted in sessions and never in money. Separate
  // from /orders, which is one payment at a time: this is the standing balance
  // those payments produce, per course.
  { href: "/billing", label: "رصيدي", Icon: CreditsIcon, audience: ["student", "guardian"] },
  /*
   * Spec 010 · US2. No permission: everyone signed in has a side of a private
   * conversation — the student writes to their teacher, the teacher and whoever
   * they authorised answer. `ListConversations` returns an empty list to anyone
   * with neither, which is a screen that says so rather than a link that 403s.
   */
  { href: "/messages", label: "الرسائل", Icon: MessagesIcon },
  { href: "/notifications", label: "الإشعارات", Icon: BellIcon },
  /*
   * Spec 030 — and the label reads from both sides now. A guardian manages
   * «المرتبطون»; a student reads who follows THEM, which is a different sentence
   * about the same rows. `labels` exists for exactly this (`/reviews` is the
   * precedent) and the screen splits its two sections the same way.
   */
  {
    href: "/family",
    label: "المرتبطون",
    labels: { student: "من يتابعني" },
    Icon: FamilyIcon,
  },
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

export const adminNav: NavItem[] = [
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
export const platformNav: NavItem[] = [
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

export const allNav = [...mainNav, ...adminNav, ...platformNav];

/**
 * ما يراهُ هذا الحسابُ من قائمةٍ ما: الصلاحيّةُ والجمهورُ معاً.
 *
 * كانتْ دالّةً داخلَ المكوّن؛ وهي هنا لأنّ ترويسةَ الموقعِ العامّةَ تحتاجُ الجوابَ
 * نفسَه، ولأنّ شرطاً يُكتَبُ مرّتَينِ يفترقُ عندَ أوّلِ جمهورٍ يُضاف.
 */
export function allowedNav(items: NavItem[], user: User | null): NavItem[] {
  /*
   | ⚠️ **`dashboardAudience()` وليست تهجئةً ثانيةً بجوارِها.** هي السؤالُ الذي
   | يُوجِّهُ `/dashboard` نفسَه إلى ثلاثةِ تخطيطات، والشريطُ الجانبيُّ يسألُ
   | السؤالَ عينَه: «أيُّ الشاشاتِ لك؟». و`isLearner()` تجيبُ بنعم عن الطالبِ
   | ووليِّ الأمرِ معاً — فتوسيعُها لتفرّقَ كان سيغيّرُ `homePathFor` و
   | `panelPathFor` من حيثُ لا يقصدُ أحد، وهما سؤالانِ آخران.
   |
   | ⚠️ وحسابٌ بلا دَورٍ وبلا صلاحيّةٍ يُقرَأُ طالباً هنا الآن، وهذا مقصود: هو ما
   | يفعلُه `/dashboard` له سلفاً (`StudentDashboard`)، فالشريطُ يلحقُ باللوحةِ لا
   | ينحرفُ عنها.
   */
  const who = dashboardAudience(user);

  return items
    .filter(
      (item) => can(user, item.permission) && (item.audience === undefined || item.audience.includes(who)),
    )
    .map((item) => {
      const named = item.labels?.[who];

      return named === undefined ? item : { ...item, label: named };
    });
}

/**
 * اسمُ الشاشةِ كما يراهُ هذا القارئ — لتقرأَه الصفحةُ نفسُها في عنوانِها.
 *
 * يعودُ بـ`undefined` لعنوانٍ ليس في القوائم، فالمُنادي يُقرِّرُ بديلَه.
 */
export function navLabel(href: string, user: User | null): string | undefined {
  return allowedNav(allNav, user).find((item) => item.href === href)?.label;
}

/**
 * الوصولُ السريعُ في قائمةِ الحساب — طلبُ ٢٠٢٦-٠٩-٠٦: «المنيو بيتغير حسب هو طالب
 * او مدرس او ادمن او ولي امر».
 *
 * ⚠️ **الدَّورُ يُرتِّبُ، والحارسُ وحدَه يُظهِر.** هذه القوائمُ ترتيبٌ لا صلاحيّة:
 * كلُّ عنوانٍ فيها يمرُّ بعدَها على {@link allowedNav} — الصلاحيّةُ والجمهورُ معاً —
 * فبندٌ لا يملكُه القارئُ يسقطُ مهما كانَ في أيِّ قائمة. والنتيجةُ أنّ خطأً في
 * تخمينِ الدَّورِ **يُعيدُ الترتيبَ ولا يكشفُ شيئاً أبداً**، وهو الفرقُ بينَ منتقٍ
 * يُشتَقُّ من قاعدةِ صاحبِ القرارِ وآخرَ يُجمَّعُ بجانبِها — العطبُ الذي دفعَ ثمنَه
 * هذا المستودعُ في `BookingEligibility` و`ListLeaderboardScopes`.
 *
 * ⚠️ ولذلك **لا قائمةَ للمدرّسِ وأخرى للمشرِف**: كلاهما يقرأُ القائمةَ نفسَها،
 * والصلاحيّاتُ هي التي تفرزُ — فمدرّسٌ بلا تصحيحٍ لا يرى لوحةَ التصحيح، وموظّفٌ
 * ماليٌّ يرى سجلَّ التحصيلِ ولا يرى حصصاً لا يُدرِّسُها. ودَورٌ يُضافُ غداً يعملُ
 * بلا سطرٍ هنا.
 *
 * ⚠️ و«وليُّ الأمرِ» يقرأُ شاشاتِ ابنِه نفسَها — قرارُ المنتَجِ مكتوبٌ في
 * {@link isLearner}: يقرأُ «تقييماتي الدورية» و«كشف التقديرات» من شاشاتِ الطالبِ
 * ذاتِها. فقائمتُه ليست ثالثةً مخترَعةً بل ترتيبٌ يبدأُ بـ`‎/family`، وهي الشاشةُ
 * الوحيدةُ التي تخصُّه هو.
 */
const QUICK_ACCESS = {
  // ⚠️ لا `/schedule` هنا: تلك شاشةُ حجوزاتِ القارئِ نفسِه، ووليُّ الأمرِ لا مقعدَ
  // له — فهي فارغةٌ له دائماً. جدولُ ابنِه في اللوحةِ نفسِها وفي `/family`.
  guardian: ["/family", "/report-cards", "/reviews", "/messages", "/notifications"],
  learner: ["/schedule", "/assignments", "/exams", "/leaderboard", "/enrollments"],
  // المدرّسُ والمشرِفُ والموظّفُ الماليُّ في قائمةٍ واحدة: الترشيحُ يفرزُ بينهم.
  staff: [
    "/manage/sessions",
    "/manage/grading",
    "/manage/courses",
    "/manage/exams",
    "/manage/settlement",
    "/manage/payments/collection",
    "/manage/compliance",
    "/members",
  ],
} as const;

/** خمسةٌ سقفاً: أطولُ من ذلك ليس وصولاً سريعاً بل نسخةً ثانيةً من الشريطِ الجانبيّ. */
const QUICK_ACCESS_LIMIT = 5;

export function quickAccessFor(user: User | null): NavItem[] {
  if (user === null) return [];

  // التهجئةُ نفسُها التي يقرؤُها الحارسُ فوق، لا مقارنةٌ بـ`platform_role` هنا.
  const who = dashboardAudience(user);
  const order =
    who === "guardian"
      ? QUICK_ACCESS.guardian
      : who === "student"
        ? QUICK_ACCESS.learner
        : QUICK_ACCESS.staff;

  // القوائمُ الثلاثُ تُرشَّحُ معاً: بندٌ يخصُّ دوراً آخرَ يسقطُ عندَ الحارسِ لا هنا.
  const mine = allowedNav([...mainNav, ...adminNav, ...platformNav], user);

  return order
    .flatMap((href) => {
      const item = mine.find((candidate) => candidate.href === href);

      return item ? [item] : [];
    })
    .slice(0, QUICK_ACCESS_LIMIT);
}
