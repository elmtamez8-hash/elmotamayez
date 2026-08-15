<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\ConceptStat;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionImport;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\GradeLevel;
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
use App\Modules\Tenancy\Support\Roles;
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
        AvailabilitySlot::class,
        GradeLevel::class,
        Subject::class,
        TeacherProfile::class,
    ]);
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
        ];

        foreach ($models as $model) {
            expect(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))
                ->toBeTrue("{$model} must use BelongsToWorkspace");
        }
    });
});
