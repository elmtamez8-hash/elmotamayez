# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Educational multi-tenant SaaS (courses → enrollment → exams → certificates → manual payments).
Monorepo: `backend/` (Laravel 13, PHP 8.5, modular monolith), `frontend/` (Next.js 15 App Router), `docker/`, `docs/`.
`AGENTS.md` holds the same command reference; `docs/README.md` has the module/endpoint/permission tables and `docs/erd.md` the schema.

## Commands

All backend commands run from `backend/`:

```bash
php vendor/bin/pest                              # all tests (120)
php vendor/bin/pest tests/Feature/Learning       # one directory
php vendor/bin/pest --filter="lesson"            # one test by name
php vendor/bin/pest --coverage                   # needs pcov/xdebug
./vendor/bin/pint                                # fix PSR-12 style (--test to check only)
./vendor/bin/phpstan analyse                     # Larastan level 8 (phpstan.neon) — see note below
php artisan migrate:fresh --seed                 # reset DB + roles/permissions + demo data
php artisan storage:link                         # once per checkout — serves uploaded receipts at /storage
php artisan serve                                # API on :8000, Filament on /admin, Horizon on /horizon
php artisan scribe:generate                      # regenerate /docs from route annotations
```

Frontend (`frontend/`): `npm run dev` (:3000, rewrites `/api/*` → `localhost:8000`), `npm run build`, `npx tsc --noEmit`, `npm test` (vitest + jsdom, ~2s, no build and no servers), `npm run test:e2e` (Playwright — builds for production, needs both servers).

Local dev uses SQLite; tests use in-memory SQLite with `SCOUT_DRIVER=null` (see `phpunit.xml`). Production is MySQL + Redis + Meilisearch. **SQLite hides column-width bugs** (it stores any integer in an `unsignedInteger` column) — check the migration when writing numeric values that MySQL would reject in strict mode.

`phpstan.neon` runs Larastan at level 8 over `app/` and `database/`, and the tree is clean — keep it that way. It reads every module's `Database/Migrations` directory to type model properties, so a new module must be listed there too.

## Architecture

### Module system

Modules live in `backend/app/Modules/{Name}/` and are **auto-discovered** by `App\Shared\Modules\ModulesServiceProvider` — it scans the directory and registers each `{Name}ServiceProvider` extending `App\Shared\Modules\Module`. Never register a module provider manually in `bootstrap/providers.php`.

The base `Module` class auto-loads, per module:
- `routes/api.php` → auto-prefixed `/api/v1` with the `api` middleware group (root `routes/api.php` is intentionally empty)
- `routes/web.php` → `web` group
- `Database/migrations/`, `Database/factories/`

Model factories currently live centrally in `backend/database/factories/Modules/{Module}/` (namespace `Database\Factories\Modules\{Module}`), not inside the module.

Cross-module coupling goes through **events**, not direct calls into another module's actions. Listeners are wired with `Event::listen()` in the subscribing module's provider `boot()` — there is no `EventServiceProvider`. Key chains:

- `PaymentApproved` → `Payments\Listeners\CreateEnrollmentFromOrder` → `EnrollmentCreated` → `Notifications\Listeners\NotifyStudentEnrolled` → `DispatchNotification`
- `CourseCompleted` → `Certificates\Listeners\IssueCertificateIfEligible` (idempotent) → `CertificateIssued` → notification. **Only** course completion issues the course certificate (owner decision 2026-09-25); a passed exam reaches it by completing its exam item — `ExamSubmitted` / `ExamPassed` → `Learning\Listeners\CompleteExamLessonOnSubmission` → `CourseCompleted` on the last item
- `WorkspaceCreated` → `Tenancy\Listeners\SeedDefaultRoles`

### Multi-tenancy

Single database, partitioned by `workspace_id`. Three moving parts:

1. `BelongsToWorkspace` trait — adds the `WorkspaceScope` global scope and auto-fills `workspace_id` on create. **Every tenant-owned model must use it.**
2. `WorkspaceScope` — filters queries by the current workspace; skipped entirely when the context resolves to `null` (Super Admin operating globally). Bypass deliberately with `withoutWorkspaceScope()` or `WorkspaceContext::withoutScope()`.
3. `WorkspaceContext` — resolves the current workspace from the session key `workspace_id`, falling back to `users.last_workspace_id`. The `EnsureCurrentWorkspace` middleware (in the `api` group and the Filament panel's `authMiddleware`) also pushes that id into spatie's `PermissionRegistrar` team id.

`WorkspaceContext` is an **application-wide singleton that caches its resolution**, so outside an HTTP request never call `set()`: a queued job or console command that does leaks its workspace into whatever the same worker handles next. Use `forWorkspace($workspace, fn () => ...)`, which restores the previous context (and team id) afterwards. In tests, the first call to `id()` freezes the resolution — query tenant models only after `Sanctum::actingAs()` / `setCurrentWorkspace()`.

Validation is a second bypass route: Laravel's `exists` rule is a raw query, so use `App\Shared\Support\WorkspaceRules::exists('table')` instead of `'exists:table,id'` for any tenant-owned table. Scout is a third — `Model::search()` hits the engine outside global scopes and must be given an explicit `->where('workspace_id', ...)`.

spatie/permission runs in **team mode with `team_id = workspace_id`**, so role/permission checks are meaningless until the team id is set — that is why tests must go through `WorkspaceContext::set()` or the `WithWorkspace` helpers. Super Admin is the platform-level `users.is_super_admin` flag, not a tenant role.

### Conventions

- Business logic lives in `Actions/` (`App\Shared\Actions\Action`, one public `handle()`), never in controllers. Controllers: validate via a Form Request → build a DTO → call an Action → return an API Resource. Controllers extend `App\Http\Controllers\Controller` and read the authenticated user via `$this->currentUser($request)`.
- Factories are resolved centrally by `AppServiceProvider::guessFactoryName()` (`App\Modules\X\Models\Y` → `Database\Factories\Modules\X\YFactory`); models don't override `newFactory()`.
- DTOs extend `App\Shared\Data\DataTransferObject` (readonly promoted props, `fromArray()` named constructor).
- Every file is `declare(strict_types=1);`.
- Business rules configured on a model (attempt limits, timers, sequencing) must be enforced in the Action that performs the operation, not only validated on the way in — the Action is the single entry point that seeders, Filament and the API share.
- Models use `HasUuid`: `getRouteKeyName()` is `uuid`, so routes and API payloads expose `uuid`, never the autoincrement id.
- Authorization is policy-based (`{Module}/Policies/`) and permission names come from `Tenancy\Support\Permissions` / role names from `Tenancy\Support\Roles` constants — do not hardcode permission strings.
- Payments go through `Payments\Contracts\PaymentProviderInterface`; `ManualTransferProvider` is the only MVP implementation. Adding a provider must not require changing order/enrollment logic.
- **Notifications go through `Notifications\Actions\DispatchNotification` and nothing else.** Business logic names a recipient and a `NotificationType` — never a channel. Channels implement `NotificationChannelInterface` and are registered with one `->tag('notification.channels')` line; `InAppChannel` and `WhatsAppChannel` are implemented (email/Telegram/SMS/push are known values marked unimplemented). `ProviderAgnosticTest` fails the build if any `Actions/` file names a channel or provider — which is why `default_country_code` sits at the top of `config/notifications.php` and not under its `whatsapp` block: the Action that normalises a phone number may not name a channel to read its own setting.
- API auth is Sanctum bearer tokens; the Filament `/admin` panel is session-based and gated by `EnsureFilamentAccess`.

### Tests

Pest, `RefreshDatabase` for all suites, plus the `Tests\Support\WithWorkspace` trait auto-applied to `Feature` (`createWorkspaceWithOwner()`, `addWorkspaceMember()`). Feature tests are the primary safety net — the critical paths listed in `AGENTS.md` (workspace isolation, permissions, enrollment/lesson gating, exam grading, certificate issuance + verification, payment approval → enrollment) must stay green.

## Gotchas

The full rules — the failure story, the measurement and the reasoning — live in `docs/gotchas/<area>.md`. **Before changing code in an area, read its file.** The lines below are headlines only; acting on a headline without its story is how several of these defects shipped twice.

### Tenancy, workspace scope, roles and permissions → [`docs/gotchas/tenancy.md`](docs/gotchas/tenancy.md)
_Read before touching `Modules/Tenancy/`, any policy, `WorkspaceScope`/`WorkspaceContext`, Filament resources, platform-permission reads and writes._

- Platform-owned entities have no scope at all — the Action is the guard.
- `WorkspaceScope` is inert for guests.
- Never call `WorkspaceContext::set()` from a job or console command.
- An Action that acts on a named student must prove the student is theirs.
- Adding a tenant-scoped model without `BelongsToWorkspace` leaks data across workspaces and no test will catch it unless you add one to `tests/Feature/Tenancy/WorkspaceIsolationTest.php`.
- `belongsToCurrentWorkspace()` raises NO objection when the context is null — and until 2026-08-26 it denied, which locked every real student out of the product.
- A platform-permission read must declare `withoutWorkspaceScope()`, AND repeat it in every eager load.
- A workspace role may never hold a platform permission, and the guard is on the MODEL.
- `roles` is workspace-scoped now, and `/admin` is why.
- Platform standing is a table, because spatie cannot express it.
- Filament Shield generates nothing here.
- ⚠️ «A STUDENT IS A MEMBER OF NO WORKSPACE» IS TRUE OF A SELF-REGISTERED STUDENT AND FALSE IN GENERAL — measured on 2026-09-09, and this file asserts it in four places.
- `WorkspaceScope` is inert for a STUDENT, not merely for a guest — and that is what guards every student route in 009.
- A permission classified by ABSENCE passes every test it has while guarding nothing.
- `WorkspaceContext::forget()` inside a listener signs a paying student out of their own course.
- A Filament LIST never calls the row policy, so a careful `view()` guards nothing on a screen.
- The assistant wall is at the CHECK, never on the role name — and `scoped()` is the third of three container lifetimes, not a style choice.
- `(int) null === 0` addresses the PLATFORM row of `feature_flags`, and the hazard is the WRITE, not the read.
- A RESOLVED context that does not match is still a DENIAL — and a finance officer who owns a workspace has a resolved context. That is FIVE layers of one defect, and each one hid the next.
- A PUBLIC PAGE THAT READS SOMETHING WORKSPACE-OWNED HAS A VICTIM, AND IT IS NOT THE VISITOR.
- A permission whose only reader sits behind a route no client calls guards NOTHING — and the screen people actually use is the one to check.
- A FILAMENT CREATE PAGE'S DEFAULT `handleRecordCreation()` IS `new Model($data)` — SO IT STAMPS THE *OFFICER'S* WORKSPACE AND DROPS EVERY NON-FILLABLE COLUMN, SILENTLY.
- A student or parent account never becomes staff, and the refusal stands at THREE doors (invite · accept · role change) through `StaffAccounts` — a null `platform_role` passes.

### Identity and sign-in → [`docs/gotchas/identity.md`](docs/gotchas/identity.md)
_Read before touching `Modules/Identity/`, auth, two-factor, devices, auth sessions._

- The device limit counts devices, not sessions.
- A correct password on a two-factor account issues no token at all.
- `2fa.required` is applied route by route, never to a group.
- The notification bell is the session heartbeat.
- `users` carries what every account has; anything true of one role gets its own table.
- A `GROUP BY … HAVING` DISCOVERY QUERY IS A PREDICATE THAT SHRINKS UNDER ITS OWN WALK, AND PAGING IT BY `OFFSET` SKIPS EXACTLY THE ROWS THE LAST PAGE FIXED — the `chunk`-vs-`chunkById` rule above, reached through an aggregate instead of a column.

### Marketplace, taxonomy and signup catalogues → [`docs/gotchas/marketplace.md`](docs/gotchas/marketplace.md)
_Read before touching `Modules/Marketplace/`, public listing, subjects/stages/school years, signup forms._

- The trust score is derived, and so is `is_publicly_listed`.
- The read for a SIGNUP FORM is not the read for the MARKETPLACE, and one cache namespace nearly made them the same thing.
- A student's YEAR is stored and their STAGE is derived, and the derivation takes two strings rather than a model.

### Courses, the tree, lessons and learning access → [`docs/gotchas/courses.md`](docs/gotchas/courses.md)
_Read before touching `Modules/Courses/`, `Modules/Learning/`, cohorts, lesson doors, progress._

- Reordering the tree is a write to ACCESS RIGHTS, not to a display order.
- The progress denominator excludes a recording through BOTH its doors.
- `LessonEditor` renders by `asset_kind`, and the ONE type that had a page of its own is where the bug came from.
- A publish is previewed by the code that performs it, never by an estimate beside it.
- Authored text is Markdown, rendered per response, never stored as HTML.
- `/learn/lessons/{lesson}` is the ONLY surface that plays a lesson, so it carries the author's branch too.
- ⛔ THE MANDATORY-GROUP LOCK WAS REPEALED, AND THIS ENTRY USED TO DESCRIBE IT AS LIVE.
- A FACTORY WHOSE DEFAULTS NAME THE SAME PARENT TWICE BUILDS A NODE THE PRODUCT CANNOT REACH, and the tree walk and the validator disagree about it in silence.
- A SCOPED RELATION READ ON THE STUDENT'S PATH RETURNS AN EMPTY COURSE, NOT AN ERROR — AND `Enrollment::course()` CARRYING THE BYPASS DOES NOT COVER WHAT HANGS OFF IT.
- «THE AUTHOR» IS THE PIVOT ROLE, NEVER MERE MEMBERSHIP — AND THE THIRD DOOR OPENED PAID CONTENT.
- LESSON CONTENT HAS FOUR DOORS, NOT ONE — AND THE ONE THAT ACTUALLY SERVES THE FILE ENDS AT `hasActiveEnrollment`.
- A deleted course KEEPS its slug: the index, both requests and the panel count soft-deleted rows, so a deleted course's public URL can never be taken by another workspace — and `CourseSlug::taken()` was the door that disagreed.
- A course's visibility is the TEACHER's call, not `courses.update`'s — an assistant edits content but `changeVisibility`/`chooseVisibility` refuse them, and the screens read the server's boolean.

### Exams, questions, practice and grading → [`docs/gotchas/assessments.md`](docs/gotchas/assessments.md)
_Read before touching `Modules/Assessments/`, exams, the question bank, practice, study rooms._

- `concept_stats.lesson_id` is `NOT NULL` and zero means "the concept overall".
- The snapshot is the denominator, and `attempt_items` is written at START.
- A question carries EXACTLY ONE correct option, and «at least one» was the rule until a teacher asked why two was allowed.
- The practice pool subtracts the exam not yet sat, and the notebook deliberately does NOT filter practice.
- `claimForGrading()` outside the transaction strands the attempt for ever, and the refusal must stand BEFORE the claim.
- `PracticePool::withheldQuestionIds()` is computed PER STUDENT, so a room's door is «my own pool contains every question in it», never «I am enrolled here».
- The course certificate issues on `CourseCompleted` ALONE, and an exam reaches it only by completing its item — and `ShouldHandleEventsAfterCommit` does NOTHING for a queued listener.
- Starting an exam asks the course's sequence, and the attempt allowance is a claim.

### Live sessions, the broadcast room and attendance → [`docs/gotchas/live-sessions.md`](docs/gotchas/live-sessions.md)
_Read before touching `Modules/LiveSessions/`, LiveKit, join tickets, the room UI, attendance._

- Attendance never passes through the broadcast provider.
- Seats are claimed by an atomic conditional UPDATE, never `count()` then `insert()` — that is the definition of the race — and never `lockForUpdate()`, which is a no-op on SQLite, so a test written around it passes locally and proves nothing about the MySQL it will run on.
- `SessionCompleted` is not `SessionDelivered`.
- The model is `ClassSession`, never `Session`.
- A freeze period is read, never written to.
- `SESSIONS_VIEW` and `ATTENDANCE_VIEW` are two different questions.
- `SessionCancelled` carries the seat holders, because by the time a listener runs nobody holds a seat.
- `teacher_profiles.attendance_rate` is the TEACHER's attendance, not their students'.
- `DomainException` extends `LogicException`, so a controller catching `RuntimeException` lets it through to a 500 — and `CancelClassSession` never stamps `room_closed_at`.
- Opening the broadcast room was a read-then-write, and a double tap bought a parent two reports for one hour.
- «إخراج» takes the video and the room's CHAT, and the seat is deliberately untouched by both.
- A provider error is an ANSWER, and both shapes of it were a 500.
- The unlock gate guards booking and joining, and deliberately not the nightly sweep.
- The heartbeat's query budget guarded the cheap third of it.
- An enum value with three READERS and no writer is a requirement everybody believed was implemented.
- The first REAL session against a live LiveKit found five defects in one run (2026-08-18), and every one of them was invisible to a suite that fakes the vendor.
- A session stays `live` after its room closes, and the badge must not repeat that.
- `AccessToken`'s default ttl is FOUR HOURS — and the SDK's own docblock says six, which is where the wrong number in this file came from.
- The API KEY travels in every ticket and the SECRET never does — a criterion banning both is a criterion that cannot pass.
- LiveKit cannot revoke a ticket and RECREATES a deleted room on the first join, so FR-009 is kept by two guards of OURS — and simplifying either one silently reopens a closed lesson.
- An eligibility endpoint must ask whether the caller is the host before answering.
- A bare `audio` / `video` on `LiveKitRoom` means PUBLISH ON CONNECT, and it put a child's bedroom in a lesson recording.
- `void promise` is not error handling, and the two halves of that fail differently.
- The join ticket carries a uuid and never a name (`FR-006`), so the participant list is a JOIN, not a payload.
- The RECORDER is a participant, so «إخراج الجميع» would have evicted it — and no test could have said so.
- «إخراج» was a provider call and nothing else, so it lasted one refresh.
- The room's three student signals are all CLIENT-WRITTEN, which decides how each one may be used.
- «لم أفهم» is not a second raised hand, and that is the whole reason it exists.
- «The previous session» means the previous one IN THIS STUDENT'S GROUP.
- Hiding an unassigned session from discovery must never swallow a seat that was paid for.
- THE HEARTBEAT ASKS WHAT CAN EVICT, NOT WHAT LET YOU IN — AND CONFLATING THE TWO COST 15 QUERIES A BEAT AND THREW PAYING STUDENTS OUT OF LESSONS.
- EVERY TIME IS SHOWN ON THE READER'S OWN CLOCK (`useViewerTimeZone()` · `UserClock`), AND THE PLATFORM ZONE DECIDES ONLY WHERE A DAY BEGINS.
- A weekly availability window is WALL-CLOCK TIME + THE TEACHER'S IANA ZONE, never UTC — a UTC weekly window cannot express Egypt's DST.
- A zone the person CHOSE (`timezone_source = manual`) outranks every browser report, and «show both clocks» is decided by the OFFSET at that instant, never the zone name.
- A private hour whose only student cancels IN TIME is called off through `CancelClassSession`; a generated open slot reopens instead, and a LATE cancellation leaves the session standing because its seat is still charged.

### Media, recordings and playback → [`docs/gotchas/media.md`](docs/gotchas/media.md)
_Read before touching `Modules/Media/`, Bunny/R2, recording ingest, the video player._

- The watermark is the renewal loop, not something watched for.
- The transcript is derived, and the caption file expires with the grant.
- `ingestFromUrl()`'s `$sourceUrl` must arrive signed, and one provider was signing it privately.
- The recording retry loop did not exist, and its absence was a teacher's pay.
- The guard against a second delivery is a conditional UPDATE, and it was a read followed by a write — with two senders by design.
- `ReconcileAssetStatus` is the self-heal, and it needed a ceiling AND a verdict — either alone is a defect.
- `recording_status` has THREE stuck values, not one — and the two nobody swept were the ones a crash leaves behind.
- A provider DECLARES which kinds it accepts, and the day that was merely assumed a PDF got a video object.
- A media asset is resolved from its own `provider` column, never from the config — and the resolver sits BESIDE the binding, not in place of it.
- `ingestFromUrl()` is the only change to 004's contract, and the download was MOVED rather than deleted.
- `videos/fetch` DOES return the new video's id — the OpenAPI schema is wrong and this file trusted it for a day.
- A presigned R2 url needs no `headers`, and `headers: []` is a 400.
- The title is still a cross-system JOIN KEY — as the RECOVERY key, not the happy path.
- A recording can be neither ready nor failed, and that middle state is load-bearing.
- `token_path` is the whole of the playback protection, and its absence passes any test that asks for the index.
- `token_path` is SIGNED as well as sent, and it goes in the PATH, not the query — two separate mistakes that each 403 every video.
- `recording_attempts` is a budget of five that everyone read as «an hour and a quarter», and nothing made that true.
- «Still encoding» spends no attempt, because that phase belongs to another job.
- A valid Bunny token 403s when the request carries NO `Referer`, and that cost an hour of hunting a signing bug that did not exist.
- The pull zone answers `Access-Control-Allow-Origin: *` — on the bytes, not only on the index.
- `PlaybackGrantResource` sends OUR route as `manifest_url`, never the manifest's own url.
- The player needed hls.js, and «صيغة غير مدعومة» was the shipped answer until 019.
- `ReconcileAssetStatus` existed since 004 and was never scheduled.

### Billing, credits, payments and plans → [`docs/gotchas/billing.md`](docs/gotchas/billing.md)
_Read before touching `Modules/Billing/`, `Modules/Payments/`, credits, withholding, webhooks, subscriptions._

- A credit is one session at ONE teacher's rate, and the account it hangs off is platform-wide.
- The floor guards BOOKING, never the recording of a debt.
- `is_withheld` is derived from FIVE inputs and stored as none of them: the balance, the ceiling, the billing mode, an open exam window, and a CURRENT terms consent.
- The credit-limit demotion IS the switch to prepaid for that student.
- `isBlocked` asks the balance as well as the floor.
- Withholding is per COURSE, never per person, and balances are never summed.
- No money reaches the student's or the teacher's screen.
- Approving a credit purchase and moving a ceiling are PLATFORM permissions, held by no tenant role.
- Reconciliation is a JOB, and the obvious check inside it is blind.
- An expiry claims its lot first and then posts with `drawsFromLots: false`.
- The dormancy notice needs its own column.
- The entry is written BEFORE the lots are drawn and before the balance moves.
- A valid signature is not a check on the amount, and the two get conflated the moment one class does both.
- A provider with no gateway accepts no notification at all.
- One capture per order is a UNIQUE COLUMN, not a partial index.
- The callback's idempotency key is TWO columns, `(provider, external_id)`.
- `(int) null` IS `0`, AND ON A SUBSCRIPTION'S DURATION THAT IS A PLAN THAT EXPIRES THE INSTANT IT IS ACTIVATED — SO THE GUARD BELONGS AT THE BRANCH, NEVER AT THE DTO.
- A BOOLEAN THAT ANSWERS A QUESTION WHICH HAS QUIETLY GROWN A THIRD ANSWER SWALLOWS THAT ANSWER AT EVERY READER, AND IT WAS SEVEN OF THEM.
- A LOT IS OPENED BY THE SIGN OF A MOVEMENT, NEVER BY ITS TYPE — and a type list left two credits with no batch behind them.
- `subscriptions.ends_on` IS INCLUSIVE — the last day that opens — so N days end on `starts + N − 1`, and a renewal already bought moves when a later freeze extends the month before it.
- A GATEWAY-CAPTURED ORDER NEVER READS `approved`, so «has this order been taken back?» is `cancelled`/`rejected`, never «not approved».

### Teacher settlement → [`docs/gotchas/settlement.md`](docs/gotchas/settlement.md)
_Read before touching `Modules/Settlement/`, teaching units, the ledger, payouts._

- Settlement and billing are two contexts with no key between them, and that absence is load-bearing.
- Counts come from `teaching_units`; money comes from `ledger_entries`.
- Settlement money is an integer in minor units, departing from Payments' `decimal(12,2)` on purpose: Laravel's `decimal:2` cast returns a string, so every sum goes through a float.
- A close claims units with `< ends_on + 1 day`, never `<= ends_on`.
- The settlement audit filters by asking for six subject types, not by removing rows.
- `SUM(ledger_entries.amount_minor)` IS the teacher's balance, payouts included.
- `ledger_entries.teaching_unit_id` has NO unique index, so a repair that writes a unit's line is idempotent only by its own predicate — `settlement:repair-unledgered-units` is a command, never a sweep.

### Notifications and channels → [`docs/gotchas/notifications.md`](docs/gotchas/notifications.md)
_Read before touching `Modules/Notifications/`, templates, WhatsApp, push, guardian fan-out._

- `Notifiable` stays on `User`, and that is deliberate.
- A notification with no template is silently dropped.
- A channel class alone delivers nothing, and the guard is a COUNT.
- Every WhatsApp message is an approved template, and `body_ar` is documentation of one — not the text that is sent.
- The verification code cannot go through `DispatchNotification`, by construction.
- A phone number is normalised where it is WRITTEN, never where it is sent, and read from `contact_verifications`, never from `users.phone`.
- Permanent vs transient is read from the provider's own answer (`is_transient` in the error body), never from a code list copied into the tree — a copied list ages at the provider's next release and then either drops real messages or burns five attempts and the rate budget on a number the first reply already refused.
- The focus-timer mute is a filter on the READ, and the design put it in the wrong place.
- Quiet hours and digesting were written in 003 and never once ran — both apply to external channels only, and `isExternal()` was false for every channel that existed until 020.
- `isFocusing()` asked `status = running` and never read the clock, so one abandoned timer muted an account's notifications for ever.
- `NotificationChannel::Push` is deliberately absent from `defaultChannels()`, and SUBSCRIBING is what switches it on.
- `ProviderAgnosticTest` fails on a private method NAMED `notify()` inside `Actions/`, and it is right to.

### Community, chat, realtime and gamification → [`docs/gotchas/community.md`](docs/gotchas/community.md)
_Read before touching `Modules/Community/`, `Modules/Gamification/`, Reverb/Echo, feature flags._

- A reversal that shares its original's idempotency key is swallowed for ever.
- A cause that was reversed is REINSTATED by negating the chain's head, never re-awarded — and a duplicate award gives its daily slot back.
- The gamification catalogue is reference data, so an empty one awards nothing and every assertion passes against zero.
- The day and week boundary lives in `GamificationCalendar` and reads the ONE platform zone — `SESSIONS_TIMEZONE` since 2026-09-25, when the panel-editable row that disagreed with the scheduler was removed.
- A picker is derived from the AUTHORISER'S OWN PREDICATE, never assembled beside it.
- A middleware list given to `withBroadcasting()` REPLACES the `api` group, and the thing it drops is spatie's team id.
- pusher-js unbinds BY FUNCTION REFERENCE, and removes every entry matching it — so two subscribers passing the SAME function are both killed by the first cleanup.
- `window.Pusher.instances[0]` is a corpse after any hot reload.
- `hidden_at`, never `deleted_at` — on `messages` and on `announcements` both.
- The broadcast payload is two identifiers, and the second reason is the one that gets «simplified» away.
- `ReadRanksFor` reads ONE week's board, and a rank and a level come from different tables.
- A departed teacher's chat closes for WRITING and stays open for reading, and the question lives in the policy rather than on the row.
- A socket host written down once is wrong for every other address the same build is opened from.
- The room's discussion lock is a COLUMN, and it is the only refusal in `ConversationPolicy::post()` that is.
- A permission nothing links to is a permission nobody has, and 021 shipped one for a phase.
- A whisper is REFUSED on a private channel and ACCEPTED on a presence one, and that single fact decides both of this spec's channels in opposite directions.

### Privacy, retention, erasure and offboarding → [`docs/gotchas/compliance.md`](docs/gotchas/compliance.md)
_Read before touching `Modules/Compliance/`, retention sweeps, data requests, legal holds, teacher exit._

- The three expiry behaviours are not interchangeable, and `Anonymise` is unavailable wherever the identifying column is `NOT NULL`.
- A legal hold has a FOURTH door, and it is the one with nobody asking.
- Retention on a class recording is a write to the COURSE TREE, and the seventh road to 016's worst defect.
- `FR-037` ends the teacher's access and their ASSISTANTS', never their students'.
- The clearance bridge is a CONTRACT because `ContextIsolationTest` says so — and that test did not cover `Compliance` until this phase widened it.
- `FR-036` cannot be a row in `data_categories`, and the reason generalises.
- The register of processors is checked against the CODE, never against itself.
- A public reporting route earns its exposure with a CONSTANT response, not with a login.
- `notified` is refused until BOTH notification timestamps exist, and neither is ever re-stamped.
- `ExecuteTeacherOffboarding` could never complete an exit for an officer whose own workspace differed, and one workspace in every fixture is what hid it.
- `PersonalDataContractCoverageTest` is a per-MODULE guard, and a NEW TABLE inside a registered module is invisible to it.

### Frontend → [`docs/gotchas/frontend.md`](docs/gotchas/frontend.md)
_Read before touching anything under `frontend/src/`._

- Frontend stores the Sanctum token in `localStorage` and sends `Authorization: Bearer` (`frontend/src/lib/api.ts`); it relies on the Next.js rewrite, so the backend must be running on :8000.
- Don't run `npm run build` while `npm run dev` is up — both write `.next/`, and the dev server then dies with `Cannot find module './NNN.js'`.
- The frontend is Arabic-only and RTL-only (spec 002).
- Colours come from `@theme` in `globals.css`, never from a class.
- Shared UI lives in `frontend/src/components/ui/` (`Button`, `Card`, `Badge`, `Alert`, `Table`, the `Field` family, `states/`).
- An Arabic counted noun goes through `counted()`, never a template literal — «٢ مدرّس متاح» shipped to the live marketplace and was reported.
- Never show a raw error to the user.
- A 500 on EVERY page is two route files resolving to one path, and route groups produce no URL segment.
- The BROWSER serves the stale chunk, and restarting the dev server does not clear it — only a hard reload does.
- A 500 on one page with a `Jest worker … exceeding retry limit` message is a corrupted `.next/`, not a bug in that page.
- A swallowed error is worse than a raw one.
- A destructive control that cannot be taken back gets `ConfirmButton`, and it is a two-press ARM rather than a dialog.
- ⚠️ EVERYTHING WRITTEN IN `globals.css` OUTSIDE `@theme` IS UNLAYERED, AND UNLAYERED CSS BEATS EVERY `@layer` — INCLUDING THE `utilities` LAYER TAILWIND v4 PUTS ITS CLASSES IN.
- A colour class naming a token `@theme` never defined paints NOTHING, silently — and it has now shipped three times.
- ⚠️ `bg-surface-muted` IS THE FOURTH UNDEFINED COLOUR TOKEN TO SHIP, and `LessonRow` had written the rule down one directory away.
- `PasswordField` is the one spelling, and `"password"` had to leave `TextField`'s type union in the same change.
- A screen with no permission gate may only make reads EVERYBODY holds — and `/dashboard` made one nobody but a teacher did.
- AN ENDPOINT NO FILE IN `frontend/src` CALLS IS A FEATURE NOBODY HAS — and three of them surfaced in one day (2026-09-06), each reported by the user as a missing product.
- A session time is drawn on `useViewerTimeZone()`, never on `session.timezone` — that field is the PLATFORM zone.

### HTTP surface, rate limits and proxies → [`docs/gotchas/http-and-security.md`](docs/gotchas/http-and-security.md)
_Read before touching routes, middleware, rate limiters, API response shapes, logging._

- Rate limiters are named (`throttle:auth` · `throttle:registration` · `throttle:public`, defined in `AppServiceProvider::registerRateLimiters()`).
- `$exception->getMessage()` in a log line is a leak, and `QueryException` is the ordinary case rather than the exotic one.
- `TRUSTED_PROXIES=*` DOES NOT MEAN «trust the proxy that called you» — it writes `['0.0.0.0/0', '::/0']`, and the method that does it is named `setTrustedProxyIpAddressesToTheCallingIp()`.
- The `api` middleware group had NO default limiter, so a route that named none carried none.
- `response()->json(Resource::collection($paginator))` NEVER CALLS `toResponse()`, so `links` and `meta` are dropped in silence and every reader is stuck on page one with nothing saying there is a page two.

### Database, migrations, MySQL vs SQLite, catalogues → [`docs/gotchas/database.md`](docs/gotchas/database.md)
_Read before touching any migration, seeder, raw query, Eloquent relation or runtime catalogue._

- A Resource runs once per row, so a query inside one is an N+1 by construction.
- `whereDate()` costs the index the column was given.
- Module directory names are matched literally by `Module::registerMigrations()`/`registerFactories()`. Migrations must live in `Database/Migrations` (capital M) — a casing mismatch resolves fine on Windows/macOS and silently loads zero migrations on Linux.
- Renumbering comes before the index, and `chunkById` before `chunk`.
- Dropping a column drops its index FIRST, in its own statement — and only SQLite will tell you.
- `insertOrIgnore` writes a row without booting the model, so `HasUuid` never fires — and on MySQL the resulting NOT NULL violation is downgraded to a warning and `''` is stored, after which *every* later entry on the platform collides with that row on `unique(uuid)`, is read as "already recorded", and is silently ski…
- The floor comparison is CAST to signed, and MySQL alone will tell you why.
- A unique index carrying a nullable column does not bite, and 008 has TWO of them.
- `SeedCommand` runs every seeder inside `Model::unguarded()`.
- A column added by a migration and not to `$fillable` is a column that silently never gets written — and spec 013 shipped THREE of them on one table.
- A `date` column compared against a bare date string is off by one day, and the day it loses is the only one that matters.
- `->with('relation:id,uuid,name')` on a User relation selects a column that does not exist, and every screen using it renders a BLANK NAME.
- A catalogue row a release adds never reaches an existing database, and this tree has now hit it THREE times.
- A required field validated against a runtime catalogue is the ONLY one of the four that fails loudly — and it fails at the front door.
- A dashboard number is stored as a numerator and a denominator, and the platform row is COMPUTED rather than summed.
- A field whose options come from a catalogue that is not seeded in production is a CLOSED DOOR — and `MarketplaceSeeder` was called only outside production.
- A column with no FOREIGN KEY makes every `->relation->field` in an Action a 500 waiting for one deleted row.
- A TRANSLATABLE COLUMN IS A JSON DOCUMENT, AND THE LOCALE IS WHAT DECIDES ITS KEY — so `config/app.php` defaulting to `'en'` would have stored `{"en": "عربي"}` on a product that has no English.
- FOUR SQL FORMS ARE GREEN ON THE WHOLE SUITE AND BREAK PRODUCTION ONLY, AND SPEC 038 WALKED INTO ALL FOUR.
- A FILAMENT EDIT FORM FILLS FROM `attributesToArray()`, AND FOR A TRANSLATABLE COLUMN THAT IS THE WHOLE DOCUMENT — SO THE PANEL RENDERED `[object Object]` AND WOULD HAVE SAVED IT.
- MySQL REFUSES ANY IDENTIFIER OVER 64 CHARACTERS AND SQLITE HAS NO LIMIT AT ALL — so the deploy is the first place that sees it, and it takes every migration behind it down with it.

### Deploy, queues and operations → [`docs/gotchas/deploy-ops.md`](docs/gotchas/deploy-ops.md)
_Read before touching `docker/`, `scripts/`, Horizon, the scheduler, env and secrets, dependencies._

- Operational numbers live in `platform_settings`, not `config/`.
- A Horizon supervisor needs `defaults` AND `environments`.
- A `queue:work` holds the code it booted with, and that is how a fixed bug keeps reporting itself.
- Provider secrets go in the ENVIRONMENT, and the repo's own "operational numbers live in `platform_settings`" rule does not apply to them.
- The npm advisories: `sharp` is FIXED, `postcss` is accepted, and the guard for what remains is CALL-SITE DISCIPLINE — not the config, whatever an earlier version of this note claimed.
- `withoutOverlapping()` on `Schedule::job()` guards the DISPATCH, not the run — for a queued job that is milliseconds around the push, released long before the worker starts, so last night's sweep still walking when tonight's begins runs two copies over the same rows.
- The backend image had NO `php.ini` until 2026-09-25 — and `opcache.validate_timestamps=0` now means a manual `artisan config:cache` in a running container is invisible to FPM until it restarts.
- A job that fails for good mails `HORIZON_NOTIFICATION_EMAIL` once per class per hour; a server that is DOWN alerts nobody without an external uptime check.
- The deploy checks out the SHA CI tested, and rollback is `bash scripts/rollback.sh` — images only, never the code or the migrations.

### Testing (pest, vitest, Playwright) → [`docs/gotchas/testing.md`](docs/gotchas/testing.md)
_Read before touching writing or debugging any test._

- A `->delay()` runs immediately on the `sync` queue connection.
- Component logic is tested with `npm test` (vitest + jsdom); Playwright answers a different question.
- `npx playwright test` needs an API that can answer concurrently, or the *build* fails before a single test runs.
- `Queue::fake()` with no arguments makes half the billing suite assert zero rows.
- Playwright projects carry an authenticated `storageState` produced by `e2e/auth.setup.ts` so the panel can be audited.
- `getContent()` escapes non-ASCII, so a leak assertion with an Arabic needle is vacuously true.
- `Http::fake()` APPENDS stub sets and the first matching pattern wins.
- A test can be green because of the WRONG condition, and `US6` shipped nine of them that way.
- A query-budget test does not catch a dropped eager load when the Resource uses `whenLoaded`.
- A test that joins a room must `Queue::fake([CloseClassSessionJob::class])`, or every later door answers 403 for the wrong reason.
- A guard that greps source must strip comments first, or it fails on the rule written beside the code.
- `WorkspaceContext::forget()` PINS THE CONTEXT TO NULL — IT DOES NOT UNRESOLVE IT — AND THAT IS WHY NO TEST IN THIS TREE COULD SEE THE DEFECT ABOVE.
- `pest --parallel` creates NO database here, and the guard is the framework's not ours — but every DB-touching line in it is inside one `if`.
- The hook that seeds reference data once per process is `$seeder`, NEVER `afterRefreshingDatabase()` — and the wrong one looks exactly like the right one.
- The three slowest tests are 5.5% of the suite and their numbers are NOT negotiable.
- A query budget needs warming until STEADY, and one warm-up request is not that.
- `RefreshDatabase` on `Unit` makes every pure-function test pay for a migration.
- A Pest helper is a GLOBAL function, so two test files may not spell one name differently.
- A SEQUENTIAL TEST OF AN ATOMIC CLAIM IS GREEN AGAINST A BUILD WITH NO CLAIM IN IT — measured again in 034's waitlist.
- A COUNTER, A LOG LINE AND A GREEN JOB EACH PROVE A NARROWER THING THAN THEY LOOK LIKE, AND THE DIFFERENCE IS WHERE THEY SIT RELATIVE TO THE `try`.
