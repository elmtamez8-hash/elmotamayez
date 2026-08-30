<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Models\Accommodation;
use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\ConceptMastery;
use App\Modules\Assessments\Models\ConceptStat;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\GradingRecord;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionImport;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Assessments\Models\RubricCriterion;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Community\Models\Announcement;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Community\Models\BlockedTerm;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaCaption;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditAllocation;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Models\StudentCreditAccount;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeacherPayout;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Store\Models\Shipment;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\BelongsToWorkspace;
use Laravel\Sanctum\Sanctum;

describe('workspace isolation', function (): void {
    it('prevents a user from switching to a workspace they do not belong to', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        // Owner A tries to switch to workspace B.
        Sanctum::actingAs($ownerA);

        $this->postJson("/api/v1/workspaces/{$workspaceB->uuid}/switch")
            ->assertForbidden();
    });

    it('prevents viewing members of another workspace', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner();
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($ownerA);

        $this->getJson("/api/v1/workspaces/{$workspaceB->uuid}/members")
            ->assertForbidden();
    });

    it('prevents inviting members to another workspace', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner();
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($ownerA);

        $this->postJson("/api/v1/workspaces/{$workspaceB->uuid}/invitations", [
            'email' => 'new@example.com',
            'role' => Roles::STUDENT,
        ])->assertForbidden();
    });
});

describe('permission enforcement', function (): void {
    it('allows tenant-owner to invite members', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'invited@example.com',
            'role' => Roles::STUDENT,
        ])->assertCreated();
    });

    it('denies a student from inviting members', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'invited@example.com',
            'role' => Roles::STUDENT,
        ])->assertForbidden();
    });

    it('allows a super-admin to access any workspace', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner();
        $superAdmin = User::factory()->superAdmin()->create();

        Sanctum::actingAs($superAdmin);

        $this->getJson("/api/v1/workspaces/{$workspaceA->uuid}/members")
            ->assertOk();
    });
});

describe('marketplace models are workspace-scoped', function (): void {
    // A tenant-owned model without BelongsToWorkspace leaks silently: it passes
    // every other test in the suite. These cases are the only thing that catches it.
    it('scopes marketplace models to the current workspace', function (string $model): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $context->forWorkspace($workspaceA, fn () => $model::factory()->count(2)->create());
        $context->forWorkspace($workspaceB, fn () => $model::factory()->count(3)->create());

        expect($context->forWorkspace($workspaceA, fn () => $model::query()->count()))->toBe(2);
        expect($context->forWorkspace($workspaceB, fn () => $model::query()->count()))->toBe(3);
    })->with([
        'availability slots' => [AvailabilitySlot::class],
        'teacher profiles' => [TeacherProfile::class],
    ]);

    /*
    | ⚠️ `Subject` AND `GradeLevel` USED TO BE IN THE LIST ABOVE, and spec 009 (Q8)
    | took them out. This case is their inverse rather than their absence: a test
    | that merely disappears leaves nothing saying the behaviour changed on
    | purpose, and the next reader restores BelongsToWorkspace as a missing guard.
    |
    | They are PLATFORM reference data now (constitution v1.2.0 §I, layer ب): one
    | "الرياضيات" for the whole product. With a workspace_id, the subject and grade
    | leaderboard scopes that are supposed to cross workspaces collapsed into
    | scopes INSIDE one — and SC-018 would have passed green against a fixture with
    | a single workspace. The guard is now the platform permission `taxonomy.manage`
    | on the write, not a scope on the read.
    */
    it('does NOT scope the taxonomy, because it belongs to the platform', function (string $model): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        /*
        | ⚠️ RELATIVE TO WHAT WAS ALREADY THERE, NEVER AN ABSOLUTE FIVE. This
        | table is PLATFORM reference data, so anything is entitled to seed a row
        | into it — and one did: a backfill migration adding a placeholder subject
        | turned «five» into «six» and failed a test about workspace scoping for a
        | reason that had nothing to do with scoping. The question here is whether
        | BOTH workspaces see the SAME vocabulary, and a baseline is how that is
        | asked without pinning a number the platform is allowed to change.
        */
        $baseline = $model::query()->count();

        $context->forWorkspace($workspaceA, fn () => $model::factory()->count(2)->create());
        $context->forWorkspace($workspaceB, fn () => $model::factory()->count(3)->create());

        // Both workspaces see all five: one vocabulary, shared by everybody.
        expect($context->forWorkspace($workspaceA, fn () => $model::query()->count()))->toBe($baseline + 5)
            ->and($context->forWorkspace($workspaceB, fn () => $model::query()->count()))->toBe($baseline + 5);
    })->with([
        'grade levels' => [GradeLevel::class],
        'subjects' => [Subject::class],
        // Spec 022 — the third of them, and it was born this way rather than
        // demoted. A workspace_id on `school_years` would give the platform one
        // «الصف العاشر» per teacher, and a student's year would then mean a
        // different row depending on who they happen to study with.
        'school years' => [SchoolYear::class],
    ]);
});

describe('gamification models are workspace-scoped', function (): void {
    /*
    | Required in the same PR that adds the model (Constitution I).
    |
    | ⚠️ ONLY THE PURSE IS SCOPED, and the asymmetry is spec 009's central decision
    | rather than an oversight. `student_progress` and `badge_awards` are PLATFORM-
    | owned — one person, one level, one streak, whatever their teachers — and are
    | covered by Gamification\PlatformOwnershipTest instead. `award_entries` carries
    | a workspace_id for CONTEXT and belongs to a student who is a member of no
    | workspace at all, so a scope on it would hide a student's own history from
    | them. What IS tenant-owned is the shop and the coins spent in it.
    */
    it('scopes a coin balance to the teacher it was earned with', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $student = User::factory()->create();
        $writer = app(ProgressWriter::class);

        $writer->coinBalanceFor((int) $student->getKey(), (int) $workspaceA->getKey());
        $writer->coinBalanceFor((int) $student->getKey(), (int) $workspaceB->getKey());

        $context = app(WorkspaceContext::class);

        expect($context->forWorkspace($workspaceA, fn () => CoinBalance::query()->count()))->toBe(1)
            ->and($context->forWorkspace($workspaceB, fn () => CoinBalance::query()->count()))->toBe(1)
            // And the student, who belongs to neither, reads both — which is why
            // every student-facing read filters by user_id EXPLICITLY rather than
            // trusting a scope that is inert for them.
            ->and(CoinBalance::query()->withoutWorkspaceScope()->where('user_id', $student->getKey())->count())
            ->toBe(2);
    });
});

describe('media models are workspace-scoped', function (): void {
    // Required in the same PR that adds the model (Constitution I). Without it a
    // missing BelongsToWorkspace passes every other test and leaks in production.
    it('scopes media assets to the current workspace', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $context->forWorkspace($workspaceA, fn () => MediaAsset::factory()->count(2)->create(['owner_id' => 1]));
        $context->forWorkspace($workspaceB, fn () => MediaAsset::factory()->count(3)->create(['owner_id' => 1]));

        expect($context->forWorkspace($workspaceA, fn () => MediaAsset::query()->count()))->toBe(2);
        expect($context->forWorkspace($workspaceB, fn () => MediaAsset::query()->count()))->toBe(3);
    });

    it('scopes captions to the current workspace', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $context->forWorkspace($workspaceA, function (): void {
            $asset = MediaAsset::factory()->create(['owner_id' => 1]);
            MediaCaption::factory()->create(['media_asset_id' => $asset->getKey()]);
        });

        expect($context->forWorkspace($workspaceB, fn () => MediaCaption::query()->count()))->toBe(0);
    });

    // Platform-owned, and their absence from the list above is the assertion:
    // a device limit copied per workspace is a fresh allowance for every teacher
    // the student enrols with, which is no limit at all.
    it('keeps devices and sessions off the workspace layer', function (): void {
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive(Device::class), true))->toBeFalse()
            ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(AuthSession::class), true))->toBeFalse()
            ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(StudentProfile::class), true))->toBeFalse();
    });
});

describe('live session models are workspace-scoped', function (): void {
    // Required in the same PR that adds the model (Constitution I). A session
    // without BelongsToWorkspace passes every other test in this suite and puts
    // one teacher's timetable in another's calendar.
    it('scopes sessions, bookings, attendance and freezes to the current workspace', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $context->forWorkspace($workspaceA, function (): void {
            $session = ClassSession::factory()->create();
            SessionBooking::factory()->create(['class_session_id' => $session->getKey()]);
            Attendance::factory()->create(['class_session_id' => $session->getKey()]);
            FreezePeriod::factory()->create();
        });

        $context->forWorkspace($workspaceB, function (): void {
            ClassSession::factory()->count(2)->create();
        });

        expect($context->forWorkspace($workspaceA, fn () => ClassSession::query()->count()))->toBe(1)
            ->and($context->forWorkspace($workspaceB, fn () => ClassSession::query()->count()))->toBe(2)
            ->and($context->forWorkspace($workspaceB, fn () => SessionBooking::query()->count()))->toBe(0)
            ->and($context->forWorkspace($workspaceB, fn () => Attendance::query()->count()))->toBe(0)
            ->and($context->forWorkspace($workspaceB, fn () => FreezePeriod::query()->count()))->toBe(0);
    });
});

describe('settlement models are workspace-scoped', function (): void {
    // Required in the same PR that adds the model (Constitution I). Money is the
    // worst possible place for a scope to be missing: one teacher reading
    // another's rate is the dispute this whole context was built to prevent, and
    // it would pass every other test in this suite.
    it('scopes rates, requests, units, ledger, periods and payouts to the current workspace', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $context->forWorkspace($workspaceA, function (): void {
            SettlementRate::factory()->create();
            RateChangeRequest::factory()->create();
            TeachingUnit::factory()->create();
            LedgerEntry::factory()->create();
            $period = SettlementPeriod::factory()->create();
            TeacherPayout::factory()->create(['settlement_period_id' => $period->getKey()]);
        });

        $context->forWorkspace($workspaceB, function (): void {
            SettlementRate::factory()->count(2)->create();
        });

        expect($context->forWorkspace($workspaceA, fn () => SettlementRate::query()->count()))->toBe(1)
            ->and($context->forWorkspace($workspaceB, fn () => SettlementRate::query()->count()))->toBe(2)
            ->and($context->forWorkspace($workspaceB, fn () => RateChangeRequest::query()->count()))->toBe(0)
            ->and($context->forWorkspace($workspaceB, fn () => TeachingUnit::query()->count()))->toBe(0)
            ->and($context->forWorkspace($workspaceB, fn () => LedgerEntry::query()->count()))->toBe(0)
            ->and($context->forWorkspace($workspaceB, fn () => SettlementPeriod::query()->count()))->toBe(0)
            ->and($context->forWorkspace($workspaceB, fn () => TeacherPayout::query()->count()))->toBe(0);
    });
});

describe('credit models are workspace-scoped', function (): void {
    /*
     * SC-016 promises a case for "the account, the transaction and the limit".
     * A tenant-owned model without `BelongsToWorkspace` passes every other test
     * in this suite and leaks in production, so these cases are the only thing
     * standing between the credit engine and one teacher reading another's
     * balances.
     *
     * `credit_allocations` is deliberately absent from the counting case and
     * covered by the trait assertion below instead: it is the one credit table
     * with no `workspace_id`, being a join between two rows that are both
     * already scoped. The standard "create in A, invisible from B" shape cannot
     * be written for a model that has no tenant key — asserting the absence is
     * the honest test, in the same direction PlatformOwnershipTest asserts its
     * mirror-image case.
     */
    it('scopes balances, transactions, lots, purchases and exam windows to the current workspace', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        // `workspace_id` is deliberately never passed: the trait fills it from
        // the current context on create, and a model missing the trait would
        // leave it null — which is the failure this is looking for.
        $seed = function (int $balances) use ($workspaceA): callable {
            return function () use ($balances, $workspaceA): void {
                foreach (range(1, $balances) as $index) {
                    $balance = CreditBalance::factory()->create([
                        'course_id' => Course::factory()->create([
                            'workspace_id' => $workspaceA->id,
                        ])->getKey(),
                    ]);

                    $transaction = CreditTransaction::create([
                        'credit_balance_id' => $balance->getKey(),
                        'type' => CreditTransactionType::Purchase,
                        'credits' => 8,
                        'source_type' => 'test',
                        'source_id' => $index,
                        'created_at' => now(),
                    ]);

                    CreditLot::create([
                        'credit_transaction_id' => $transaction->getKey(),
                        'credit_balance_id' => $balance->getKey(),
                        'credits_total' => 8,
                        'credits_remaining' => 8,
                    ]);
                }

                ExamModeWindow::factory()->create();
            };
        };

        $context->forWorkspace($workspaceA, $seed(1));
        $context->forWorkspace($workspaceB, $seed(2));

        foreach ([CreditBalance::class, CreditTransaction::class, CreditLot::class] as $model) {
            expect($context->forWorkspace($workspaceA, fn () => $model::query()->count()))->toBe(1)
                ->and($context->forWorkspace($workspaceB, fn () => $model::query()->count()))->toBe(2);
        }

        expect($context->forWorkspace($workspaceA, fn () => ExamModeWindow::query()->count()))->toBe(1)
            ->and($context->forWorkspace($workspaceB, fn () => ExamModeWindow::query()->count()))->toBe(1);
    });

    it('declares the tenant key on every credit model that has one', function (): void {
        $scoped = [
            CreditBalance::class,
            CreditTransaction::class,
            CreditLot::class,
            CreditPurchase::class,
            ExamModeWindow::class,
        ];

        foreach ($scoped as $model) {
            expect(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))
                ->toBeTrue("{$model} must use BelongsToWorkspace");
        }

        // And the two that must NOT have it. Adding the trait to a platform-owned
        // account produces one duplicate person per teacher; adding it to the
        // allocation join adds a third copy of a key nothing queries, on a table
        // whose only readers already hold scoped transaction ids.
        foreach ([StudentCreditAccount::class, CreditAllocation::class] as $model) {
            expect(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))
                ->toBeFalse("{$model} must NOT use BelongsToWorkspace");
        }
    });
});

describe('course tree models are workspace-scoped', function (): void {
    /*
     * Sections and chapters predate 016, and until it they had no public
     * identifier at all — the endpoints took serial ids, which nothing in the
     * product ever called, so nothing ever noticed. 016 gave them uuids, a
     * lifecycle and eight routes each. A tenant-owned model without
     * `BelongsToWorkspace` passes every other test in this suite and leaks in
     * production; these cases are the only thing that catches it.
     */
    it('scopes every node of the tree to the current workspace', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $build = function (int $lessons, int $workspaceId): callable {
            return function () use ($lessons, $workspaceId): void {
                // `workspace_id` stated, because `CourseFactory` hardcodes 1 and
                // would quietly file both academies' courses under the first one.
                $course = Course::factory()->create(['workspace_id' => $workspaceId]);

                $section = Section::create([
                    'course_id' => $course->id, 'title' => 'قسم',
                    'status' => ContentStatus::Published, 'order' => 1,
                ]);

                $chapter = Chapter::create([
                    'course_id' => $course->id, 'section_id' => $section->id,
                    'title' => 'فصل', 'status' => ContentStatus::Published, 'order' => 1,
                ]);

                foreach (range(1, $lessons) as $index) {
                    Lesson::create([
                        'course_id' => $course->id, 'section_id' => $section->id,
                        'chapter_id' => $chapter->id, 'title' => "درس {$index}",
                        'type' => 'article', 'content' => 'نصّ',
                        'status' => ContentStatus::Published, 'order' => $index,
                    ]);
                }
            };
        };

        // `workspace_id` is deliberately NOT passed: the trait fills it from the
        // current context on create, and a model missing the trait would leave it
        // null — which is the failure this is looking for.
        $context->forWorkspace($workspaceA, $build(2, (int) $workspaceA->id));
        $context->forWorkspace($workspaceB, $build(3, (int) $workspaceB->id));

        foreach ([Section::class, Chapter::class] as $model) {
            expect($context->forWorkspace($workspaceA, fn () => $model::query()->count()))->toBe(1)
                ->and($context->forWorkspace($workspaceB, fn () => $model::query()->count()))->toBe(1);
        }

        expect($context->forWorkspace($workspaceA, fn () => Lesson::query()->count()))->toBe(2)
            ->and($context->forWorkspace($workspaceB, fn () => Lesson::query()->count()))->toBe(3);
    });

    it('keeps one workspace out of another tree through the API', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $foreign = $context->forWorkspace(
            $workspaceB,
            fn () => Course::factory()->create(['workspace_id' => $workspaceB->id]),
        );

        Sanctum::actingAs($ownerA);
        $this->setCurrentWorkspace($workspaceA, $ownerA);

        // Not 200-with-an-empty-tree: the course is not this teacher's to read at
        // all, and answering "here is an empty course" would confirm the uuid
        // names something.
        $this->getJson("/api/v1/courses/{$foreign->uuid}/tree")->assertNotFound();
    });
});

describe('provider callbacks are scoped once they can be', function (): void {
    /*
     * ⚠️ THE LATE PATH, NOT THE HAPPY ONE. A callback arrives with no tenant —
     * there is no user on a webhook, so WorkspaceScope adds no condition at all —
     * and `workspace_id` is filled when the row is PROCESSED, from the order it
     * names. Testing only a resolved row would miss the window this table spends
     * most of its life in.
     */
    it('hides a resolved callback from another workspace and shows an unresolved one to nobody', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        ProviderCallback::factory()->create([
            'workspace_id' => $workspaceA->getKey(),
            'external_id' => 'evt_a',
        ]);

        // The row in the state it arrives in: no tenant yet.
        ProviderCallback::factory()->create(['external_id' => 'evt_unresolved']);

        expect($context->forWorkspace($workspaceA, fn () => ProviderCallback::query()->count()))->toBe(1)
            // ⚠️ ZERO, not one: the unresolved row is invisible to every
            // workspace, which is exactly right — nobody can yet say whose it is.
            // Only the deferred worker reads it, and it says
            // withoutWorkspaceScope() out loud.
            ->and($context->forWorkspace($workspaceB, fn () => ProviderCallback::query()->count()))->toBe(0);

        expect(ProviderCallback::query()->withoutWorkspaceScope()->count())->toBe(2);
    });

    it('declares the tenant key on the callback table, and nowhere it does not belong', function (): void {
        // ⚠️ BOTH DIRECTIONS. The trait is what hides a resolved row from another
        // teacher; and the nullable column is what lets an unattributed one exist
        // at all. Dropping the trait leaks every callback across the platform;
        // making the column NOT NULL forces the tenant to be read from a payload
        // the other side wrote, which is not an authorisation source.
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive(ProviderCallback::class), true))
            ->toBeTrue('ProviderCallback must use BelongsToWorkspace');

        $callback = ProviderCallback::factory()->create(['external_id' => 'evt_null_ok']);

        expect($callback->workspace_id)->toBeNull();
    });
});

describe('question bank models are workspace-scoped', function (): void {
    it('hides one teacher’s bank, concepts and frozen items from another', function (): void {
        [$mine, $me] = $this->createWorkspaceWithOwner();
        [$theirs, $them] = $this->createWorkspaceWithOwner();

        $this->setCurrentWorkspace($theirs, $them);
        $theirExam = Exam::create([
            'workspace_id' => $theirs->id, 'uuid' => Str::uuid(),
            'title' => 'اختبارهم', 'max_attempts' => 3, 'status' => 'published',
        ]);
        bankQuestion($theirs, $theirExam, ['content' => 'سؤالٌ لا يخصّني؟']);

        $this->setCurrentWorkspace($mine, $me);
        $myExam = Exam::create([
            'workspace_id' => $mine->id, 'uuid' => Str::uuid(),
            'title' => 'اختباري', 'max_attempts' => 3, 'status' => 'published',
        ]);
        bankQuestion($mine, $myExam, ['content' => 'سؤالي؟']);

        expect(Question::query()->pluck('content')->all())->toBe(['سؤالي؟'])
            ->and(ExamItem::query()->count())->toBe(1)
            ->and(Concept::query()->where('workspace_id', $theirs->id)->count())->toBe(0);
    });

    it('hides one teacher’s uploads and their row-by-row reports from another', function (): void {
        [$mine, $me] = $this->createWorkspaceWithOwner();
        [$theirs, $them] = $this->createWorkspaceWithOwner();

        $this->setCurrentWorkspace($theirs, $them);
        QuestionImport::create([
            'workspace_id' => $theirs->id, 'uploaded_by' => $them->id,
            'original_filename' => 'بنكهم.csv', 'stored_path' => 'imports/theirs.csv',
            'duplicate_policy' => DuplicatePolicy::Skip,
        ]);

        $this->setCurrentWorkspace($mine, $me);

        // ⚠️ THE REPORT CARRIES THE TEXT OF EVERY ROW THAT FAILED — so an import
        // list without the tenant key is one teacher reading the questions
        // another teacher could not import. `ImportController::index` has no
        // filter of its own; the global scope is the whole guard.
        expect(QuestionImport::query()->count())->toBe(0);
    });

    it('declares the tenant key on every bank model', function (): void {
        // ⚠️ Spec 008 adds eleven tables and not one of them is platform-owned.
        // The one that was argued for — accommodations — is a BRIDGE: extra time
        // applies to one teacher's assessments, and granting it is that teacher's
        // act recorded in their name (research.md §و).
        $models = [
            Concept::class, Question::class, ExamItem::class, AttemptItem::class,
            QuestionImport::class,
            // ⚠️ The two rollup tables belong here as much as the rest. Their
            // writer passes `workspace_id` explicitly — `upsert()` boots no model,
            // so the trait's auto-fill never runs — which means the READ side is
            // the only place the tenant key is enforced at all.
            QuestionStat::class, ConceptStat::class,
            // US5 and US6. `accommodations` is the one that was argued about and
            // it belongs here: extra time applies to ONE teacher's assessments,
            // and granting it is that teacher's act recorded in their name. The
            // mirror-image bug — making it platform-owned like the notification
            // preferences — would hand every teacher on the platform the fact
            // that a student has an arrangement (FR-056).
            RubricCriterion::class, GradingRecord::class,
            Assignment::class, Submission::class, Accommodation::class,
            /*
            | Spec 012's two, and both are BRIDGES: the concept and its questions
            | are the teacher's, the student is the platform's, so the row carries
            | `workspace_id` for context and points at the platform-wide user —
            | exactly what `enrollments` does.
            |
            | ⚠️ AND THIS CASE IS THE WEAKER HALF OF THEIR GUARD, WHICH IS WHY IT
            | IS NOT THE ONLY ONE. `WorkspaceScope` adds no condition when the
            | context is null and it is null for EVERY student, so the trait
            | protects the teacher's side and almost nothing on the path that
            | actually reaches these tables. The real guard is the explicit
            | `student_user_id` condition inside each Action, measured in
            | `AdaptiveClaimTest` with a student built the way the product builds
            | one — no membership, no `last_workspace_id`, no context.
            */
            AdaptiveSession::class, ConceptMastery::class,
        ];

        foreach ($models as $model) {
            expect(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))
                ->toBeTrue("{$model} must use BelongsToWorkspace");
        }
    });
});

/*
| Spec 013 — the ONE workspace-owned model the compliance module adds.
|
| ⚠️ REQUIRED IN THE SAME COMMIT AS THE MODEL (Constitution I), and its own
| migration says so in writing. Everything else in that module is
| platform-owned — the catalogue, the requests, the holds, the sweep log — and an
| earlier draft of the plan concluded from that "no workspace-owned model is
| added, so no isolation case is needed". That was wrong, and it was wrong
| because nobody had DECLARED this one: the thing being wound down IS a
| workspace, so the request is about one.
|
| Without the trait, one teacher's exit request appears on every other teacher's
| screen — including the amount they are waiting on and the date their students
| lose access.
*/
it('scopes a teacher offboarding to its own workspace', function (): void {
    [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    $context->forWorkspace($workspaceA, function () use ($ownerA): void {
        TeacherOffboarding::query()->create(['teacher_user_id' => $ownerA->getKey()]);
    });

    $context->forWorkspace($workspaceB, function () use ($ownerB): void {
        TeacherOffboarding::query()->create(['teacher_user_id' => $ownerB->getKey()]);
    });

    expect($context->forWorkspace($workspaceA, fn () => TeacherOffboarding::query()->count()))->toBe(1)
        ->and($context->forWorkspace($workspaceB, fn () => TeacherOffboarding::query()->count()))->toBe(1)
        // ⚠️ AND THE AUTO-FILL IS ASSERTED, not just the read filter. The trait
        // does two things, and a model that scoped reads while writing a null
        // tenant key would pass a count assertion and be invisible to everyone
        // including its owner.
        ->and($context->forWorkspace($workspaceA, fn () => (int) TeacherOffboarding::query()->value('workspace_id')))
        ->toBe($workspaceA->getKey())
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(TeacherOffboarding::class), true))
        ->toBeTrue();
});

/*
| Spec 010 — the assistant assignment, and the scope row that deliberately is not
| workspace-scoped.
|
| ⚠️ REQUIRED IN THE SAME COMMIT AS THE MODEL (Constitution I). Without the trait
| one teacher's team appears on every other teacher's screen, with a «إزالة»
| button beside each name — and nothing else in the suite would say so.
*/
it('scopes an assistant assignment to its own workspace', function (): void {
    [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    $context->forWorkspace($workspaceA, fn () => AssistantAssignment::factory()->count(2)->create());
    $context->forWorkspace($workspaceB, fn () => AssistantAssignment::factory()->count(3)->create());

    expect($context->forWorkspace($workspaceA, fn () => AssistantAssignment::query()->count()))->toBe(2)
        ->and($context->forWorkspace($workspaceB, fn () => AssistantAssignment::query()->count()))->toBe(3)
        // ⚠️ AND THE AUTO-FILL IS ASSERTED, not only the read filter. The trait
        // does two things, and a model that scoped reads while writing a null
        // tenant key would pass a count assertion and be invisible to everyone
        // including its owner.
        ->and($context->forWorkspace($workspaceA, fn () => (int) AssistantAssignment::query()->value('workspace_id')))
        ->toBe($workspaceA->getKey())
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(AssistantAssignment::class), true))
        ->toBeTrue();
});

/*
| ⚠️ THE MIRROR-IMAGE CASE, AND IT ASSERTS AN ABSENCE ON PURPOSE.
|
| `assistant_scopes` carries no `workspace_id` and no global scope by design: the
| row is reachable only through its assignment, which carries both. That makes the
| JOIN the guard, and it makes a bare `AssistantScope::query()` as exposed as any
| platform-owned table — which is exactly what this asserts, so that nobody
| "fixes" it later by adding the trait and silently duplicating one confinement
| per workspace. The guard being tested is that the DIRECTORY starts from an
| assignment, not that the model filters.
*/
it('leaves assistant scopes unscoped and guards them through the assignment', function (): void {
    [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    $assistantA = $context->forWorkspace($workspaceA, function () use ($workspaceA) {
        $assignment = AssistantAssignment::factory()->create(['workspace_id' => $workspaceA->getKey()]);
        AssistantScope::factory()->create([
            'assistant_assignment_id' => $assignment->getKey(),
            'course_id' => Course::factory()->create(['workspace_id' => $workspaceA->getKey()])->getKey(),
        ]);

        return $assignment->assistant;
    });

    $context->forWorkspace($workspaceB, function () use ($workspaceB): void {
        $assignment = AssistantAssignment::factory()->create(['workspace_id' => $workspaceB->getKey()]);
        AssistantScope::factory()->create([
            'assistant_assignment_id' => $assignment->getKey(),
            'course_id' => Course::factory()->create(['workspace_id' => $workspaceB->getKey()])->getKey(),
        ]);
    });

    // The model is deliberately NOT scoped — both rows are visible to a raw query.
    expect(in_array(BelongsToWorkspace::class, class_uses_recursive(AssistantScope::class), true))
        ->toBeFalse('assistant_scopes carries no tenant key; adding the trait would duplicate one confinement per workspace')
        ->and(AssistantScope::query()->count())->toBe(2);

    // The directory is what confines it: A's assistant is confined in A, and is
    // not an assistant in B at all — so B places no confinement on them, which is
    // "not restricted here", never "restricted to nothing".
    $directory = app(AssistantScopeDirectory::class);

    expect($directory->scopedCourseIdsFor($assistantA, $workspaceA->getKey()))->toHaveCount(1)
        ->and($directory->isAssistantIn($assistantA, $workspaceB->getKey()))->toBeFalse()
        ->and($directory->scopedCourseIdsFor($assistantA, $workspaceB->getKey()))->toBeNull();
});

/*
| Spec 010 US2 — conversations and messages.
|
| ⚠️ TWO WORKSPACES AND A STUDENT IN EACH, because a single-workspace fixture
| cannot fail. `WorkspaceScope` is inert when the context is null, and the context
| is null for every student on the platform — they are members of nothing. So the
| trait's read filter is asserted from a MEMBER's context here, and the student's
| own exposure is measured over HTTP in `ConversationAccessTest`, which is the
| only place it can be.
|
| ⚠️ AND `messages.workspace_id` IS ASSERTED AGAINST THE CONVERSATION'S, not
| merely against non-null. The sender is usually a student, so the auto-fill has
| nothing to write; a teacher signed into a second workspace would have it write
| the WRONG one from `users.last_workspace_id`. `PostMessage` copies it from the
| conversation, and this is what fails if anybody removes that line and leans on
| the trait.
*/
it('scopes conversations and messages to the workspace that owns them', function (): void {
    [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    $conversationA = $context->forWorkspace($workspaceA, function () use ($ownerA) {
        $conversation = Conversation::factory()->create();

        Message::query()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->getKey(),
            'sender_user_id' => $ownerA->getKey(),
            'body' => 'داخل أ',
        ]);

        return $conversation;
    });

    $context->forWorkspace($workspaceB, function (): void {
        Conversation::factory()->count(2)->create();
    });

    expect($context->forWorkspace($workspaceA, fn () => Conversation::query()->count()))->toBe(1)
        ->and($context->forWorkspace($workspaceB, fn () => Conversation::query()->count()))->toBe(2)
        ->and($context->forWorkspace($workspaceA, fn () => Message::query()->count()))->toBe(1)
        ->and($context->forWorkspace($workspaceB, fn () => Message::query()->count()))->toBe(0)
        // The message carries its CONVERSATION's workspace, whoever wrote it.
        ->and((int) Message::query()->withoutWorkspaceScope()->value('workspace_id'))
        ->toBe((int) $conversationA->workspace_id)
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(Conversation::class), true))->toBeTrue()
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(Message::class), true))->toBeTrue()
        /*
        | The mirror image: `conversation_participants` carries no tenant key and
        | must not grow one. It is reachable only through a conversation that has
        | one, and adding the trait would file one person's read pointer per
        | workspace — the duplication `PlatformOwnershipTest` guards in both
        | directions.
        */
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(ConversationParticipant::class), true))
        ->toBeFalse('conversation_participants is reached through its conversation, which carries the tenant key');
});

/*
| Spec 010 US3 — the moderation record and the term list.
|
| ⚠️ THE TERM LIST ESPECIALLY. A filter that reads another teacher's list either
| refuses words this teacher allows or — the direction that matters — permits
| words they banned, and both failures are silent: nothing errors, and the first
| anyone knows is a screenshot. Two workspaces, because a single-workspace fixture
| returns the same rows with the condition and without it.
*/
it('scopes moderation actions and blocked terms to the workspace that owns them', function (): void {
    [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    // ⚠️ Every workspace is BORN with a term list (`SeedDefaultBlockedTerms`), so
    // the baseline is counted rather than assumed to be zero — an assertion of
    // «one row» would be wrong for a reason that has nothing to do with scoping.
    $seeded = $context->forWorkspace($workspaceA, fn () => BlockedTerm::query()->count());

    expect($seeded)->toBeGreaterThan(0);

    $context->forWorkspace($workspaceA, function () use ($ownerA): void {
        BlockedTerm::factory()->create(['term' => 'مصطلحُ أ']);

        ModerationAction::factory()->create([
            'actor_user_id' => $ownerA->getKey(),
            'subject_id' => $ownerA->getKey(),
        ]);
    });

    $context->forWorkspace($workspaceB, function (): void {
        BlockedTerm::factory()->count(2)->create();
    });

    expect($context->forWorkspace($workspaceA, fn () => BlockedTerm::query()->count()))->toBe($seeded + 1)
        ->and($context->forWorkspace($workspaceB, fn () => BlockedTerm::query()->count()))->toBe($seeded + 2)
        ->and($context->forWorkspace($workspaceA, fn () => ModerationAction::query()->count()))->toBe(1)
        ->and($context->forWorkspace($workspaceB, fn () => ModerationAction::query()->count()))->toBe(0)
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(BlockedTerm::class), true))->toBeTrue()
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(ModerationAction::class), true))->toBeTrue();
});

/*
| Spec 010 · US4 — the periodic assessment.
|
| ⚠️ AND THE TENANT SCOPE IS THE WEAKER HALF OF ITS GUARD, WHICH IS WHY IT NEEDS
| SAYING HERE. It bites for the TEACHER's screen, where the reader is a member;
| on the student's own route `WorkspaceContext::id()` is null — a student belongs
| to no workspace — so the scope adds no condition at all and the explicit
| `student_user_id` filter is the whole protection. Both halves are measured:
| this case for the teacher, `PeriodicReviewTest` for the student.
*/
it('scopes periodic reviews to the workspace that wrote them', function (): void {
    [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    // One student studying with BOTH teachers — the shape that makes the scope
    // load-bearing. A fixture with one student per workspace passes whether or not
    // the scope exists.
    $student = User::factory()->create();

    $context->forWorkspace($workspaceA, fn () => PeriodicReview::factory()->create([
        'student_user_id' => $student->getKey(),
        'teacher_user_id' => $ownerA->getKey(),
    ]));

    $context->forWorkspace($workspaceB, fn () => PeriodicReview::factory()->create([
        'student_user_id' => $student->getKey(),
        'teacher_user_id' => $ownerB->getKey(),
    ]));

    expect($context->forWorkspace($workspaceA, fn () => PeriodicReview::query()->count()))->toBe(1)
        ->and($context->forWorkspace($workspaceB, fn () => PeriodicReview::query()->count()))->toBe(1)
        ->and(PeriodicReview::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(PeriodicReview::class), true))->toBeTrue();
});

/*
| ⚠️ AND AN ANNOUNCEMENT IS THE ONE ROW HERE WHOSE LEAK IS A BROADCAST.
|
| Everything else in this file leaks a row to a reader. `announcements` carries a
| `scope_id` pointing at a course, and a scope that resolved outside its own
| workspace would not show one teacher another teacher's notice — it would SEND
| that teacher's class a message in the wrong name. The tenant scope is the first
| of the two locks; `AnnouncementScopeTest` measures the second, inside the Action
| that resolves the uuid.
*/
it('scopes announcements to the workspace that published them', function (): void {
    [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    $context->forWorkspace($workspaceA, fn () => Announcement::factory()->create([
        'author_user_id' => $ownerA->getKey(),
    ]));

    $context->forWorkspace($workspaceB, fn () => Announcement::factory()->create([
        'author_user_id' => $ownerB->getKey(),
    ]));

    expect($context->forWorkspace($workspaceA, fn () => Announcement::query()->count()))->toBe(1)
        ->and($context->forWorkspace($workspaceB, fn () => Announcement::query()->count()))->toBe(1)
        ->and(Announcement::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(Announcement::class), true))->toBeTrue();
});

/*
| ⚠️ AND THE RECORD REFUSES TO BE REWRITTEN, ON THE MODEL. `moderation_actions` IS
| the record `FR-021` asks for — who acted, on whom, why — and an Action is one
| caller while a model is every caller. `LedgerEntry` guards itself the same way.
*/
it('refuses an edit or a deletion of a moderation record', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    $action = app(WorkspaceContext::class)->forWorkspace($workspace, fn () => ModerationAction::factory()->create([
        'actor_user_id' => $owner->getKey(),
        'subject_id' => $owner->getKey(),
    ]));

    expect(fn () => $action->update(['reason' => 'سبب آخر']))->toThrow(RuntimeException::class)
        ->and(fn () => $action->delete())->toThrow(RuntimeException::class);
});

/*
| Spec 021 — the four new tenant-owned tables (Constitution I, non-negotiable).
|
| ⚠️ A TENANT-SCOPED MODEL WITHOUT `BelongsToWorkspace` LEAKS ACROSS WORKSPACES
| AND NO OTHER TEST IN THIS REPOSITORY WILL SAY SO. The trait check beside the
| count is not decoration: a count of one can also be produced by a fixture that
| happened to create one row, so both halves are asserted for every table.
*/
it('scopes the four cohort tables to the workspace that owns them', function (): void {
    [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    $seed = fn ($workspace, $owner) => $context->forWorkspace($workspace, function () use ($workspace, $owner): void {
        $course = Course::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
        ]);

        $cohort = Cohort::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $owner->getKey(),
        ]);

        $student = User::factory()->create();

        CohortMembership::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'cohort_id' => $cohort->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
        ]);

        CohortMembershipEvent::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'cohort_id' => $cohort->getKey(),
            'student_user_id' => $student->getKey(),
            'actor_user_id' => $owner->getKey(),
        ]);

        CohortTransferRequest::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'to_cohort_id' => $cohort->getKey(),
            'student_user_id' => $student->getKey(),
        ]);
    });

    $seed($workspaceA, $ownerA);
    $seed($workspaceB, $ownerB);

    foreach ([Cohort::class, CohortMembership::class, CohortMembershipEvent::class, CohortTransferRequest::class] as $model) {
        expect($context->forWorkspace($workspaceA, fn () => $model::query()->count()))->toBe(1)
            ->and($context->forWorkspace($workspaceB, fn () => $model::query()->count()))->toBe(1)
            ->and($model::query()->withoutGlobalScopes()->count())->toBe(2)
            ->and(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))->toBeTrue();
    }
});

/*
| SC-015 · spec 011 — the store's three tables.
|
| ⚠️ A TENANT-SCOPED MODEL WITHOUT `BelongsToWorkspace` LEAKS ACROSS WORKSPACES AND
| NO OTHER TEST WOULD SAY SO. That is why this file exists, and why the trait
| itself is asserted alongside the counts: a model that happened to be filtered by
| an explicit `where` in today's only caller would pass the count and fail the day
| a second caller forgets.
*/
it('scopes the three store tables to the workspace that owns them', function (): void {
    [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    $context = app(WorkspaceContext::class);

    $seed = fn ($workspace) => $context->forWorkspace($workspace, function () use ($workspace): void {
        $item = StoreItem::factory()->physical(4)->create([
            'workspace_id' => $workspace->getKey(),
        ]);

        $order = StoreOrder::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'store_item_id' => $item->getKey(),
        ]);

        Shipment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'store_order_id' => $order->getKey(),
        ]);
    });

    $seed($workspaceA);
    $seed($workspaceB);

    foreach ([StoreItem::class, StoreOrder::class, Shipment::class] as $model) {
        expect($context->forWorkspace($workspaceA, fn () => $model::query()->count()))->toBe(1)
            ->and($context->forWorkspace($workspaceB, fn () => $model::query()->count()))->toBe(1)
            ->and($model::query()->withoutGlobalScopes()->count())->toBe(2)
            ->and(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))->toBeTrue();
    }
});
