# Specification Quality Checklist: الاشتراكُ من صفحةِ الكورس — مجموعةٌ أو حصصٌ خاصّة

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-05
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

**On «No implementation details» — a deliberate, bounded exception.**
The «⛔ ما قِيسَ في الشجرة» table names files, routes and columns on purpose. This
repository's rule is «read the artifact before naming a cause»: a spec that asserted
«the course page has no way to subscribe» without the measurement would be a claim, and
this tree has already paid for design documents built on unverified claims of absence.
Spec 026 carries the same shape. **The requirements themselves (FR-001…FR-038) name no
file, no route, no column and no framework** — they are readable by someone who has never
opened the codebase, which is what the item protects. The one exception inside a
requirement is FR-018's reference to `FR-008أ` of spec 024, which is a cross-spec
governance citation rather than an implementation detail, and FR-019's parenthetical
reason — both are the *why*, not the *what*.

**On zero clarification markers.** Four decisions were made as informed defaults rather
than asked, and every one is written in «Assumptions» so `/speckit-clarify` can surface
it: (1) the vehicle is the duration plan, not the credit package; (2) the queue lives on
the page the product owner named, beside the manual grant form; (3) the private-session
approval notice promises bookable hours rather than a booked appointment; (4) group
«schedule» in the notice is the published group timetable, not an auto-booking promise.

**Two findings this spec records that were not in the original request**, both measured
2026-09-05 and both load-bearing:

- Approving a credit order grants **no enrolment at all** — so a paying student stays
  outside the curriculum, outside the group and outside the private-session door.
  Covered by FR-024.
- A self-enrolment path grants access to any published course **free**, with no order and
  no approval. Any payment gate built on top of this feature is decorative while that
  stands. Covered by FR-004.

**Clarify session 2026-09-05 — ten assumptions decided, all ten as recommended.**
Eight confirmed what was written; **two changed it**. Assumption 4 now requires the
approval notice to carry the weekly timetable **and** the next dated session with its
link (FR-029 · FR-029أ). Assumption 10 reversed: group sessions are now **booked
automatically for the length of the subscription**, which added the whole of section «ح»
(FR-039…FR-047), three edge cases, and SC-006أ/SC-006ب. The re-validation below was run
against that updated spec — every new requirement in «ح» has either an acceptance
scenario (US3 · 2أ), an edge case, or a success criterion behind it, which is why the
«All functional requirements have clear acceptance criteria» item still passes.

**Revised after the first review (2026-09-05, before clarify).** Three items were tightened before sign-off:
FR-005 now covers **first registration**, not only sign-in — the course page is a public,
search-reachable page, so most people who press the button have no account at all, and
sending them to a dashboard to start over is exactly the friction the request asks to
remove; FR-005أ says what happens when the minors' rules interrupt that path; and FR-014
now pins **what was bought** as a whole (name · duration · session type) rather than the
duration alone, so a plan renamed after the order cannot make the student's purchase and
the officer's queue row disagree.

**Open for `/speckit-clarify`** (not blocking, and each has a written default):
the exact placement of the queue relative to the manual grant form; whether a private
subscription should pick its first appointment at subscribe time; and whether renewal
should be offered from the course page or only from «اشتراكاتي».

- **تحديث ٢٠٢٦-٠٩-٠٥ (بعدَ مراجعةِ الوكلاءِ الخمسة)**: أُضيفتْ أربعةُ متطلَّبات — FR-039أ
  (تخطّي حصّةٍ ثُبِّتَ عددُ مقاعدِها) · FR-045أ (ما حرّرَه النظامُ يُعادُ حجزُه، وما ألغاه
  الطالبُ لا) · FR-048 وFR-048أ (تسعيرُ مقعدِ المشترِكِ في التسويةِ عبرَ الحدثِ وحدَه) —
  وقراران لصاحبِ المنتَجِ مسجَّلانِ في «Clarifications · Session 2026-09-05 (ب)». الأربعةُ
  قابلةٌ للقياسِ ولا تحملُ تفصيلَ تنفيذ، فلا بندَ في هذه القائمةِ يتغيّرُ حالُه: ١٦/١٦.
