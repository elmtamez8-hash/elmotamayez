# Tasks: الحصة التجريبية لكل كورس

**Input**: Design documents from `specs/040-teacher-trial-lesson/` (plan, spec, research R1–R9, data-model, contracts/api.md, quickstart)

**Tests**: Included. The constitution's green gates and CLAUDE.md make Pest feature tests the safety net, and SC-003/SC-004 are only provable by tests.

**Paths**: `B/` = `backend/app/Modules/`, `T/` = `backend/tests/Feature/`, `F/` = `frontend/src/`

## Phase 1: Setup

- [ ] T001 Read `docs/gotchas/courses.md`, `marketplace.md`, `media.md`, `database.md`, `http-and-security.md` and `frontend.md` entries touched by research R1–R9 before writing code (no file output)

## Phase 2: Foundational (blocks every story)

- [ ] T002 Migration `B/Courses/Database/Migrations/2026_10_09_000100_add_trial_lesson_to_courses.php`:
  - nullable `trial_lesson_id`, FK → `lessons` with `nullOnDelete` and an explicit constraint name (≤64 chars).
  - `down()` drops it in a separate `Schema::table` call, as `add_promo_video_to_courses.php` does.
  - Verify with plain `php artisan migrate`, **never** `migrate:fresh`.
- [ ] T003 `Course::trialLesson(): BelongsTo` plus `@property int|null $trial_lesson_id` in `B/Courses/Models/Course.php`. Do **not** add the column to `$fillable` (R1).
- [ ] T004 `B/Courses/Support/TrialLessonRule.php`, per R3 and the data-model table:
  - `scopeEligible(Builder<Lesson>)`: the static conditions, `visibleToStudents()`, and for `video` a `whereHas('mediaAsset')` with kind video, provider bunny and a playable status.
  - `refusalFor(Course, Lesson): ?string`: the Arabic messages.
  - Both read the same condition constants.
- [ ] T005 Add `?CarbonImmutable $expiresAt = null` to `B/Media/Data/PlaybackContext.php`. `B/Media/Providers/BunnyMediaProvider.php::manifest()` uses it when set, otherwise `grant->expires_at`, so the student path is unchanged.
- [ ] T006 [P] Settings and limiter:
  - Add `media.trial_link_ttl_seconds` (600) and `media.trial_requests_per_minute` (30) to `B/Tenancy/Support/PlatformSettings.php` `KEYS`, with defaults in `backend/config/media.php`.
  - Add two fields to `B/Tenancy/Filament/Pages/ManagePlatformSettings.php`.
- [ ] T007 Register limiter `trial-playback` in `backend/app/Providers/AppServiceProvider.php::registerRateLimiters()`: key `ip:`+IP, with the `isOwnServerRender` branch like `public`.
- [ ] T008 [P] `T/Courses/TrialLessonRuleTest.php`. `refusalFor` and `scopeEligible` must agree on every refusal case:
  - wrong course, article, session recording, release session, cohort scope, high value;
  - local-provider video, video not ready;
  - and accept embed, and a ready Bunny video.

**Checkpoint**: column, rule, settings and limiter exist; no behaviour visible yet.

## Phase 3: US2 — المدرّس يختار الحصة (P1) 🎯 needed by every other story

**Independent test**: PUT marks and moves the trial, refuses ineligible lessons with their message, refuses an assistant with 403, and a stale clear does not wipe a newer pick.

- [ ] T009 [US2] `CoursePolicy::chooseTrialLesson()` in `B/Courses/Policies/CoursePolicy.php`: the same shape as `changePricing()`, with refusal «الحصة التجريبية يختارها مدرّس الكورس.»
- [ ] T010 [P] [US2] DTO `B/Courses/Data/SetTrialLessonData.php` (`?string $lesson`, `?string $replacing`) and `B/Courses/Http/Requests/SetCourseTrialLessonRequest.php`:
  - `lesson` nullable uuid via `WorkspaceRules::exists('lessons','uuid')`;
  - `replacing` required when `lesson` is null.
- [ ] T011 [US2] `B/Courses/Actions/SetCourseTrialLesson.php`:
  - mark with `refusalFor` and then `forceFill(...)->save()`;
  - clear with a conditional `UPDATE … WHERE id=? AND trial_lesson_id=?` (R2);
  - return the current state.
- [ ] T012 [US2] `B/Courses/Http/Controllers/CourseTrialLessonController.php` (authorize → DTO → Action) and `B/Courses/Http/Resources/CourseTrialLessonResource.php` (`trial_lesson`, `trial_status`). Route `PUT /courses/{course}/trial-lesson` in `B/Courses/routes/api.php`.
- [ ] T013 [US2] Editor fields per R9:
  - `B/Courses/Http/Resources/CourseResource.php`: `can_choose_trial`, `trial_status`, `trial_lesson_uuid`.
  - `B/Courses/Http/Resources/LessonResource.php`: `is_trial` and `trial_refusal`, on `make()` only and never per tree row.
- [ ] T014 [P] [US2] `T/Courses/CourseTrialLessonTest.php` covers:
  - mark and move;
  - each refusal message;
  - assistant 403;
  - lesson of another course (also another workspace) 422;
  - stale `replacing` leaves the newer pick;
  - deleting the lesson nulls the column;
  - a soft-deleted course hides the trial (it reappears on restore).
- [ ] T015 [US2] Frontend API in `F/lib/courses.ts`: `setTrialLesson(courseUuid, lesson|null, replacing?)` and the new resource fields.
- [ ] T016 [US2] Toggle «اجعله الحصة التجريبية لهذا الكورس» in `F/components/courses/editors/LessonEditor.tsx`:
  - shown when `can_choose_trial`;
  - disabled with `trial_refusal` as its hint;
  - warns «سيشاهده أي زائر بلا حساب» and «ستنتقل العلامة من …» before saving (FR-005);
  - errors through `userMessage()`.
- [ ] T017 [US2] vitest for the toggle beside the editor's existing tests: hidden for assistants, sends `replacing` on clear, shows the refusal hint.

**Checkpoint**: a teacher can pick the trial for each course.

## Phase 4: US1 + US6 — الزائر يشاهد، والباب لا يفتح غيرها (P1) 🎯 MVP

**Independent test**: a course with a marked embed or Bunny video plays at `/courses/{slug}/trial` with no account. Every refused lesson kind answers the same 404, and student playback tests pass untouched.

- [ ] T018 [US1] `B/Marketplace/Actions/Public/ReadCourseTrial.php`:
  - resolves the course by `publiclyListed()` (lightweight), then the lesson via `whereKey(trial_lesson_id)->where('course_id')->tap(TrialLessonRule::scopeEligible)->with('mediaAsset')`, with `withoutWorkspaceScope()` commented;
  - every miss → `NotFoundHttpException('غير متاح')`.
- [ ] T019 [US1] `B/Marketplace/Http/Controllers/PublicCourseTrialController.php`:
  - `show`: embed → `embed_url`; video → `playback` descriptor, with `reload_after_seconds` = 2/3 TTL and a minimum of 30.
  - `stream`: `MediaProviderResolver` then `manifest(new PlaybackContext(asset, grant: ?, expiresAt: now+TTL))`, then a 302 with `Cache-Control: no-store`; a non-redirect provider → 404.
  - Resource `B/Marketplace/Http/Resources/PublicCourseTrialResource.php`.
  - Note: T005 must let `PlaybackContext` take no saved grant. Make `grant` nullable only if `expiresAt` is set, and assert that in the constructor.
- [ ] T020 [US1] Routes in `B/Marketplace/routes/api.php`: `GET /marketplace/courses/{courseKey}/trial` and `/trial/stream`, with `throttle:trial-playback` and `->withoutMiddleware('throttle:api')` on the `/chat-media` pattern, with a comment. Add the payload keys to `B/Marketplace/Support/PublicFieldAllowlist.php`.
- [ ] T021 [P] [US6] `T/Marketplace/CourseTrialDoorTest.php`:
  - guest gets embed and video (Bunny faked), and a signed-in teacher from another workspace gets the same;
  - 404 for: no trial, unpublished lesson, unpublished section, course not public, soft-deleted course, teacher unlisted, session recording, cohort scoped, high value, local provider, asset not ready, embed on `/stream`;
  - byte-identical 404 bodies;
  - no `playback_grants` row and no events;
  - 429 after the limit, while `/marketplace/teachers` stays OK;
  - `Cache-Control: no-store` on both routes.
- [ ] T022 [P] [US1] Player split:
  - move everything except `<Watermark>` from `F/components/player/VideoPlayer.tsx` into `F/components/player/VideoPlayerCore.tsx`;
  - `VideoPlayer` renders Core plus Watermark with identical behaviour;
  - existing player tests must pass unchanged (SC-004).
- [ ] T023 [US1] `F/components/marketplace/TrialPlayer.tsx`:
  - fetches `/marketplace/courses/{key}/trial` **in the browser**;
  - embed → `EmbeddedVideo`; video → `VideoPlayerCore`;
  - loading and error states through `userMessage()`.
  - Plus vitest: no `renew` request, no watermark.
- [ ] T024 [US1] Page `F/app/(public)/courses/[slug]/trial/page.tsx`:
  - server-rendered frame (course title, teacher, back link, enrol CTA as on the lesson preview page) plus `<TrialPlayer>`;
  - `generateMetadata`;
  - `notFound()` when the course has no `trial`.
- [ ] T025 [US1] `PublicCourseDetailResource` gets `trial` (one query via the rule). The course page `F/app/(public)/courses/[slug]/page.tsx` gets the button «شاهد حصة تجريبية مجاناً» → `/courses/{slug}/trial` (inbound link, FR-015).
- [ ] T026 [P] [US1] Extend `T/Marketplace/PublicExposureTest.php` fixtures with a course that has a trial, so the new keys are walked.

**Checkpoint (MVP)**: a teacher picks a trial, and a visitor watches it from the course page.

## Phase 5: US3 — صفحة المدرّس والشارات (P1)

- [ ] T027 [US3] `ShowPublicTeacher`:
  - remove the automatic `trialLessonOf` from #375;
  - add `trialsOf(Collection $courses)`, one query over `whereIn('id', courses.trial_lesson_id)` with `scopeEligible`;
  - `PublicMarketplaceController::teacher` sends `trial_lessons` and sets `has_trial` on each card. Allowlist updated.
- [ ] T028 [US3] `has_trial` on the `/marketplace/courses` list cards: one query per page in the list Action, not per row.
- [ ] T029 [US3] Rewrite the #375 tests in `T/Marketplace/PublicPreviewLessonTest.php`: the auto-pick is gone, and an open embed lesson that is not marked is no trial. Add `trial_lessons` cases (zero, one, two; unlisted course ignored).
- [ ] T030 [P] [US3] Badge «حصة تجريبية مجانية» in `F/components/marketplace/CourseCard.tsx` when `has_trial`, plus a test in `CourseCard.test.tsx`.
- [ ] T031 [US3] `F/components/marketplace/TrialCta.tsx`:
  - one trial → link to it;
  - several → a list (course, subject, grade) in a popover or sheet on the teacher page;
  - types in `F/lib/public-api.ts`;
  - update `TrialCta.test.tsx`.

## Phase 6: US4 — الفيديو التعريفي بديلاً (P2)

- [ ] T032 [US4] In `F/app/(public)/teachers/[slug]/page.tsx`:
  - add `id="intro-video"` to the intro section;
  - decide the fallback by `videoEmbedUrl(intro_video_url) !== null`;
  - `TrialCta` gets «شاهد فيديو المدرّس» → `?tab=about#intro-video`, else the courses tab;
  - tests in `TrialCta.test.tsx`.

## Phase 7: US5 — تشجيع المدرّس (P2)

- [ ] T033 [US5] Persistent notice on `F/app/(app)/(shell)/manage/courses/[uuid]/page.tsx` when the course is published and `trial_status` is null: «الطلاب يحجزون أكثر حين يرون شرحك» with a link to the curriculum to pick a lesson. Test in that page's `page.test.tsx`.

## Phase 8: Polish

- [ ] T034 Copy pass on «حصة تجريبية» strings changed in #375 (home, pricing, signup) so they say a lesson per course.
- [ ] T035 Gotcha entry in `docs/gotchas/media.md`: the trial door is the only guest byte path; Bunny-only; it never creates a grant; `VideoPlayerCore` vs `VideoPlayer`. Add its headline to the CLAUDE.md Media list.
- [ ] T036 Gates: pint, phpstan, tsc, and targeted pest/vitest from quickstart. Then the quickstart browser checks 1, 3, 4, 5 and 7 locally (2 needs Bunny). Then open the PR and let CI run the full suite.

## Dependencies

- Phase 2 → all stories.
- US2 (Phase 3) → US1/US6 and US3 need a way to mark; tests can seed the column directly, so Phase 4 tests may start in parallel with Phase 3 UI.
- US3 depends on T018/T004.
- US4 is independent of the backend.
- US5 depends on T013.

## Parallel examples

- T006, T008 alongside T002–T005.
- T010 and T014 while T011 is written.
- T021, T022 and T026 together.
- T030 alongside T027–T029.

## Implementation strategy

MVP = Phases 2 + 3 + 4 (pick a trial, watch it from the course page). Then US3 (teacher page and badges), then US4/US5, then polish. One PR, or two if the review prefers (MVP first).
