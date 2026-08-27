<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Models\LeaderboardEntry;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Gamification\Support\LeaderboardScope;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| FR-019 — the rank and the level sit beside the name in a public room.
|
| ⚠️ AND ABSENCE IS A STATE, NOT AN ERROR — which is the whole of this file. The
| boards are rolled up nightly, so a student who signed up this morning has no
| row in `leaderboard_entries` at all; a teacher and an assistant have none ever,
| because neither is on a board by construction. A payload that read a rank
| straight off an entry would put a `null` where a number goes, or worse, a zero —
| «المركز ٠» beside the teacher's own name in front of the class.
|
| Three senders with nothing, one with everything, in one room:
|   - a brand-new student: no board row AND no progress row
|   - a student with a level but no board row (signed up mid-week)
|   - the teacher, and an assistant
|   - one student who has both, as the control — without it every assertion here
|     is satisfied by a payload that dropped the fields entirely.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 10);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo(Permissions::CHAT_REPLY);

    $this->ranked = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->levelled = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->newcomer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    foreach ([$this->ranked, $this->levelled, $this->newcomer] as $student) {
        $this->createEnrollment($this->workspace, $this->course, $student);

        SessionBooking::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $this->session->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => BookingStatus::Booked,
        ]);
    }

    // The control: a board row for this week in this teacher's scope, plus the
    // cumulative progress every earning student carries.
    LeaderboardEntry::query()->create([
        'scope_key' => LeaderboardScope::Teacher->keyFor((string) $this->workspace->getKey()),
        'period_key' => app(GamificationCalendar::class)->weekKey(),
        'user_id' => $this->ranked->getKey(),
        'rank' => 3,
        'points' => 120,
        'level_band' => 2,
    ]);

    foreach ([[$this->ranked, 2], [$this->levelled, 4]] as [$student, $level]) {
        StudentProgress::query()->create([
            'user_id' => $student->getKey(),
            'xp' => $level * 100,
            'level' => $level,
        ]);
    }
});

it('stamps a rank only on the sender who has one and a level on everyone who does', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    $senders = [
        'teacher' => $this->owner,
        'assistant' => $this->assistant,
        'ranked' => $this->ranked,
        'levelled' => $this->levelled,
        'newcomer' => $this->newcomer,
    ];

    foreach ($senders as $label => $sender) {
        if ($sender === $this->owner || $sender === $this->assistant) {
            $this->setCurrentWorkspace($this->workspace, $sender);
        }

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/conversations/{$uuid}/messages", [
            'body' => 'رسالة من '.$label,
        ])->assertCreated();
    }

    Sanctum::actingAs($this->ranked);

    $rows = $this->getJson("/api/v1/conversations/{$uuid}/messages")->assertOk()->json();

    $byUuid = [];

    foreach ($rows as $row) {
        $byUuid[(string) $row['sender_uuid']] = $row;
    }

    $of = fn (User $user): array => $byUuid[(string) $user->uuid];

    expect($of($this->ranked)['sender_rank'])->toBe(3)
        ->and($of($this->ranked)['sender_level'])->toBe(2)
        // A level with no board row: the week's roll-up has not seen them yet,
        // and their level is cumulative and true today.
        ->and($of($this->levelled)['sender_rank'])->toBeNull()
        ->and($of($this->levelled)['sender_level'])->toBe(4)
        // Nothing at all, rendered as nothing — never as zero.
        ->and($of($this->newcomer)['sender_rank'])->toBeNull()
        ->and($of($this->newcomer)['sender_level'])->toBeNull()
        // A teacher and an assistant are on no board by construction, and a rank
        // beside the teacher's name would be a number with no meaning.
        ->and($of($this->owner)['sender_rank'])->toBeNull()
        ->and($of($this->owner)['sender_level'])->toBeNull()
        ->and($of($this->assistant)['sender_rank'])->toBeNull()
        ->and($of($this->assistant)['sender_level'])->toBeNull()
        // The control that the fields exist at all: dropping them entirely would
        // satisfy every null assertion above.
        ->and($of($this->newcomer))->toHaveKeys(['sender_rank', 'sender_level']);
});

it('costs the same number of queries whatever the number of senders', function (): void {
    /*
    | ⚠️ ONE BULK READ FOR THE PAGE, NOT ONE PER MESSAGE. A Resource runs once per
    | row, so a rank lookup inside it is an N+1 by construction — the defect
    | `ClassSessionResource` already cost this repository once, and `ReadRanksFor`
    | was built bulk for exactly this caller.
    */
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    $url = "/api/v1/conversations/{$uuid}/messages";

    foreach ([$this->ranked, $this->levelled] as $sender) {
        Sanctum::actingAs($sender);
        $this->postJson($url, ['body' => 'أولى'])->assertCreated();
    }

    Sanctum::actingAs($this->ranked);
    $this->getJson($url)->assertOk();

    [$small] = countingQueries(fn () => $this->getJson($url)->assertOk());

    foreach ([$this->ranked, $this->levelled, $this->newcomer] as $sender) {
        Sanctum::actingAs($sender);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson($url, ['body' => 'تالية '.$i])->assertCreated();
        }
    }

    Sanctum::actingAs($this->ranked);
    [$large, $response] = countingQueries(fn () => $this->getJson($url)->assertOk());

    expect($large)->toBe($small)
        ->and($response->json())->toHaveCount(20);
});

/*
| ⚠️ الشارةُ كانت تقرأُ `level_band` لا `level`، فطُبِعَ «المستوى ٠» جنبَ اسمِ
| طالبٍ حقيقيٍّ في نقاشٍ حيٍّ — قِيسَ على قاعدةِ التطوير، لا بقراءةِ الكود.
|
| `level_band` جوابُ «في أيِّ فئةٍ نافسَ هذه الفترة»، مشتقٌّ من
| `MAX(award_entries.level_band)`، وصفٌّ بلا فئةٍ يحملُ صفراً مشروعاً. ومستوياتُ
| الفهرسِ من ١ إلى ٦، فالصفرُ ليس واحداً منها — وكان الحقلُ يعني شيئَين حسبَ ما
| إذا كانت التجميعةُ الليليّةُ قد رأتِ الشخصَ أم لا، وهو فرقٌ لا يراه قارئُ الشاشة.
|
| التجهيزُ الأصليُّ أعلاه لا يستطيعُ رؤيةَ هذا: `level_band` و`level` فيه ٢ و٢.
*/
it('prints the student level and never the band, when the two disagree', function (): void {
    $bandless = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $bandless);

    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $bandless->getKey(),
        'status' => BookingStatus::Booked,
    ]);

    // On the board — and the band its awards produced is zero.
    LeaderboardEntry::query()->create([
        'scope_key' => LeaderboardScope::Teacher->keyFor((string) $this->workspace->getKey()),
        'period_key' => app(GamificationCalendar::class)->weekKey(),
        'user_id' => $bandless->getKey(),
        'rank' => 9,
        'points' => 40,
        'level_band' => 0,
    ]);

    StudentProgress::query()->create([
        'user_id' => $bandless->getKey(),
        'xp' => 500,
        'level' => 5,
    ]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    Sanctum::actingAs($bandless);
    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'رسالة'])->assertCreated();

    $row = collect($this->getJson("/api/v1/conversations/{$uuid}/messages")->assertOk()->json())
        ->firstWhere('sender_uuid', (string) $bandless->uuid);

    expect($row['sender_rank'])->toBe(9)
        // The student's own level, never the band — and NEVER a zero, which is
        // what «المستوى ٠» is made of.
        ->and($row['sender_level'])->toBe(5);
});

/*
| ومن لا مستوى له إطلاقاً يبقى `null` — الغيابُ حالةٌ، والشاشةُ لا ترسمُ شيئاً.
*/
it('leaves the level null for a board row whose owner has earned nothing', function (): void {
    $onlyBand = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $onlyBand);

    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $onlyBand->getKey(),
        'status' => BookingStatus::Booked,
    ]);

    LeaderboardEntry::query()->create([
        'scope_key' => LeaderboardScope::Teacher->keyFor((string) $this->workspace->getKey()),
        'period_key' => app(GamificationCalendar::class)->weekKey(),
        'user_id' => $onlyBand->getKey(),
        'rank' => 11,
        'points' => 10,
        'level_band' => 0,
    ]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    Sanctum::actingAs($onlyBand);
    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'رسالة'])->assertCreated();

    $row = collect($this->getJson("/api/v1/conversations/{$uuid}/messages")->assertOk()->json())
        ->firstWhere('sender_uuid', (string) $onlyBand->uuid);

    expect($row['sender_rank'])->toBe(11)
        ->and($row['sender_level'])->toBeNull();
});
