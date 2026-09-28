<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Actions\PostMessage;
use App\Modules\Community\Data\PostMessageData;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Support\TeacherInboxSettings;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| «تواصل مع المدرّس» (owner decisions 2026-09-28).
|
| A SUBSCRIBER (an active or completed enrolment in the workspace) writes without
| limit. A PROSPECT — a student or a guardian's child who studies elsewhere or
| nowhere — may open a thread and send `community.chat.prospect_message_cap`
| messages (3) until somebody on the teacher's side answers; the first answer
| lifts the cap for good. The teacher can switch prospects off, which touches
| nobody who studies with them. A guardian writes AS their child, in the child's
| one thread, and the budget is the thread's, not the person's.
|
| ⚠️ THE CONVERSATION IS BORN WITH ITS FIRST MESSAGE. `POST /conversations`
| carries the words; nothing inserts an empty thread any more.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner(['name' => 'Nour Academy']);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->course = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->subscriber = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->subscriber);

    // Studies nowhere: a member of no workspace, the self-registered shape.
    $this->prospect = User::factory()->create(['platform_role' => PlatformRole::Student]);
});

function contactAsProspect(object $test, string $body = 'هل الكورس مناسب للصف الثالث؟'): TestResponse
{
    Sanctum::actingAs($test->prospect);

    return $test->postJson('/api/v1/conversations', ['workspace' => $test->workspace->uuid, 'body' => $body]);
}

function contactThread(object $test, User $student): ?Conversation
{
    return Conversation::query()->withoutWorkspaceScope()
        ->where('workspace_id', $test->workspace->getKey())
        ->where('student_user_id', $student->getKey())
        ->first();
}

function contactGuardian(User $child, array $permissions = [GuardianPermission::Messages]): User
{
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    ParentStudentRelation::factory()->parent()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $child->getKey(),
        'permissions' => array_map(fn (GuardianPermission $p): string => $p->value, $permissions),
    ]);

    return $guardian;
}

it('writes no conversation until the first message, and refuses a request with no words', function (): void {
    Sanctum::actingAs($this->subscriber);

    $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    expect(contactThread($this, $this->subscriber))->toBeNull();

    $uuid = $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
        'body' => 'السلام عليكم يا أستاذ',
    ])->assertCreated()->json('uuid');

    $thread = contactThread($this, $this->subscriber);

    expect($thread?->uuid)->toBe($uuid)
        ->and(Message::query()->withoutWorkspaceScope()->where('conversation_id', $thread?->getKey())->pluck('body')->all())
        ->toBe(['السلام عليكم يا أستاذ'])
        ->and($thread?->last_message_id)->not->toBeNull();
});

it('lets a subscriber write without any cap', function (): void {
    Sanctum::actingAs($this->subscriber);

    $uuid = $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid, 'body' => 'الأولى'])
        ->assertCreated()->json('uuid');

    foreach (range(2, 6) as $n) {
        $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => "رسالة {$n}"])->assertCreated();
    }

    expect(Message::query()->withoutWorkspaceScope()->where('conversation_id', contactThread($this, $this->subscriber)?->getKey())->count())
        ->toBe(6);
});

it('caps a prospect at three messages until the teacher replies, then lifts the cap for good', function (): void {
    $uuid = contactAsProspect($this)->assertCreated()->json('uuid');
    $url = "/api/v1/conversations/{$uuid}/messages";

    $this->postJson($url, ['body' => 'الثانية'])->assertCreated();
    $this->postJson($url, ['body' => 'الثالثة'])->assertCreated();

    // The fourth, and the sentence says the teacher has not answered yet.
    $refused = $this->postJson($url, ['body' => 'الرابعة'])->assertUnprocessable();
    expect((string) $refused->json('message'))->toContain('ولم يردّ المدرّس بعد');
    expect(Message::query()->withoutWorkspaceScope()->where('body', 'الرابعة')->exists())->toBeFalse();

    // The teacher's side answers — which a prospect thread allows, because the
    // prospect wrote first.
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);
    $this->postJson($url, ['body' => 'أهلاً، نعم مناسب.'])->assertCreated();

    expect(contactThread($this, $this->prospect)?->staff_replied_at)->not->toBeNull();

    Sanctum::actingAs($this->prospect);
    foreach (['الرابعة', 'الخامسة', 'السادسة'] as $body) {
        $this->postJson($url, ['body' => $body])->assertCreated();
    }
});

it('reads the cap from platform_settings', function (): void {
    PlatformSettings::set('community.chat.prospect_message_cap', 1);

    $uuid = contactAsProspect($this)->assertCreated()->json('uuid');

    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'الثانية'])->assertUnprocessable();
});

it('counts a hidden message against the budget', function (): void {
    $uuid = contactAsProspect($this)->assertCreated()->json('uuid');
    $url = "/api/v1/conversations/{$uuid}/messages";
    $this->postJson($url, ['body' => 'الثانية'])->assertCreated();
    $this->postJson($url, ['body' => 'الثالثة'])->assertCreated();

    Message::query()->withoutWorkspaceScope()->where('body', 'الثالثة')->update(['hidden_at' => now()]);

    $this->postJson($url, ['body' => 'الرابعة'])->assertUnprocessable();
});

it('never lets the teacher open a thread with somebody who does not study with them', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
        'student' => $this->prospect->uuid,
        'body' => 'عرض خاص!',
    ])->assertForbidden();

    expect(contactThread($this, $this->prospect))->toBeNull();

    // The control: their own student.
    $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
        'student' => $this->subscriber->uuid,
        'body' => 'تذكير بالحصّة',
    ])->assertCreated();
});

it('shuts prospects out when the teacher switches the setting off, and nobody else', function (): void {
    // A prospect thread that already exists, and the teacher's own student.
    $uuid = contactAsProspect($this)->assertCreated()->json('uuid');

    app(TeacherInboxSettings::class)->setAcceptsProspects($this->workspace->refresh(), false);

    // A new prospect cannot open one…
    $other = User::factory()->create(['platform_role' => PlatformRole::Student]);
    Sanctum::actingAs($other);
    $refused = $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid, 'body' => 'سؤال'])
        ->assertForbidden();
    expect((string) $refused->json('message'))->toContain('لا يستقبل هذا المدرّس');
    expect(contactThread($this, $other))->toBeNull();

    // …the existing prospect cannot post into theirs…
    Sanctum::actingAs($this->prospect);
    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'الثانية'])->assertForbidden();

    // …and the subscriber is untouched.
    Sanctum::actingAs($this->subscriber);
    $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid, 'body' => 'سؤال عن الواجب'])
        ->assertCreated();
});

it('lets only the owner read and flip the setting', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/inbox-settings')->assertOk()->assertJsonPath('data.accepts_prospects', true);
    $this->putJson('/api/v1/inbox-settings', ['accepts_prospects' => false])
        ->assertOk()->assertJsonPath('data.accepts_prospects', false);

    expect(app(TeacherInboxSettings::class)->acceptsProspects($this->workspace->refresh()))->toBeFalse();

    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->setCurrentWorkspace($this->workspace, $assistant);
    Sanctum::actingAs($assistant);

    $this->getJson('/api/v1/inbox-settings')->assertForbidden();
    $this->putJson('/api/v1/inbox-settings', ['accepts_prospects' => true])->assertForbidden();
});

it('lets a guardian write as their child, keyed on the child, and labels the line for the teacher', function (): void {
    $guardian = contactGuardian($this->subscriber);
    Sanctum::actingAs($guardian);

    $uuid = $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
        'student' => $this->subscriber->uuid,
        'body' => 'أنا وليّ أمر الطالب، متى الامتحان؟',
    ])->assertCreated()->json('uuid');

    $thread = contactThread($this, $this->subscriber);
    expect($thread?->uuid)->toBe($uuid)
        ->and((int) $thread?->student_user_id)->toBe((int) $this->subscriber->getKey());

    // The guardian's list carries it, titled with the teacher AND the child.
    $row = collect($this->getJson('/api/v1/conversations')->assertOk()->json())->firstWhere('uuid', $uuid);
    expect($row)->not->toBeNull()
        ->and((string) $row['counterparty_name'])->toContain('Nour Academy');

    // The child sees the same thread in theirs.
    Sanctum::actingAs($this->subscriber);
    expect(collect($this->getJson('/api/v1/conversations')->assertOk()->json())->pluck('uuid')->all())->toContain($uuid);

    // The teacher reads WHO wrote it.
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);
    $this->getJson("/api/v1/conversations/{$uuid}/messages")
        ->assertOk()
        ->assertJsonPath('0.sent_by_guardian', true)
        ->assertJsonPath('0.sender_name', $guardian->name);
});

it('refuses a guardian whose relation lacks the messages permission, or is not accepted', function (): void {
    $withoutPermission = contactGuardian($this->subscriber, [GuardianPermission::Attendance, GuardianPermission::Results]);
    Sanctum::actingAs($withoutPermission);

    $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
        'student' => $this->subscriber->uuid,
        'body' => 'سؤال',
    ])->assertForbidden();

    $pending = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    ParentStudentRelation::factory()->parent()->pending()->create([
        'guardian_user_id' => $pending->getKey(),
        'student_user_id' => $this->subscriber->getKey(),
    ]);
    Sanctum::actingAs($pending);

    $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
        'student' => $this->subscriber->uuid,
        'body' => 'سؤال',
    ])->assertForbidden();

    expect(contactThread($this, $this->subscriber))->toBeNull();
});

it('shares one prospect budget between the student and their guardian', function (): void {
    $guardian = contactGuardian($this->prospect);

    $uuid = contactAsProspect($this)->assertCreated()->json('uuid');
    $url = "/api/v1/conversations/{$uuid}/messages";
    $this->postJson($url, ['body' => 'الثانية'])->assertCreated();

    Sanctum::actingAs($guardian);
    $this->postJson($url, ['body' => 'وأنا وليّ أمره'])->assertCreated();
    $this->postJson($url, ['body' => 'الرابعة من الأسرة'])->assertUnprocessable();

    Sanctum::actingAs($this->prospect);
    $this->postJson($url, ['body' => 'الرابعة'])->assertUnprocessable();
});

it('offers a guardian one option per child, and a sentence when there are none', function (): void {
    $guardian = contactGuardian($this->subscriber);
    ParentStudentRelation::factory()->parent()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $this->prospect->getKey(),
        'permissions' => GuardianPermission::values(),
    ]);

    Sanctum::actingAs($guardian);
    $data = $this->getJson('/api/v1/conversations/contact-options?workspace='.$this->workspace->uuid)
        ->assertOk()->json('data');

    expect($data['note'])->toBeNull()
        ->and(collect($data['options'])->pluck('student_uuid')->sort()->values()->all())
        ->toBe(collect([(string) $this->subscriber->uuid, (string) $this->prospect->uuid])->sort()->values()->all());

    $bySubscriber = collect($data['options'])->keyBy('student_uuid');
    expect($bySubscriber[(string) $this->subscriber->uuid]['is_subscriber'])->toBeTrue()
        ->and($bySubscriber[(string) $this->subscriber->uuid]['remaining'])->toBeNull()
        ->and($bySubscriber[(string) $this->prospect->uuid]['is_subscriber'])->toBeFalse()
        ->and($bySubscriber[(string) $this->prospect->uuid]['remaining'])->toBe(3)
        ->and($bySubscriber[(string) $this->prospect->uuid]['can_start'])->toBeTrue();

    $childless = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    Sanctum::actingAs($childless);
    $none = $this->getJson('/api/v1/conversations/contact-options?workspace='.$this->workspace->uuid)
        ->assertOk()->json('data');

    expect($none['options'])->toBe([])
        ->and((string) $none['note'])->toContain('العائلة');
});

it('tells the button a prospect cannot write while the setting is off, and hands a subscriber their thread', function (): void {
    Sanctum::actingAs($this->subscriber);
    $uuid = $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid, 'body' => 'مرحباً'])
        ->assertCreated()->json('uuid');

    app(TeacherInboxSettings::class)->setAcceptsProspects($this->workspace->refresh(), false);

    $mine = $this->getJson('/api/v1/conversations/contact-options?workspace='.$this->workspace->uuid)->assertOk()->json('data.options.0');
    expect($mine['conversation_uuid'])->toBe($uuid)
        ->and($mine['can_start'])->toBeTrue();

    Sanctum::actingAs($this->prospect);
    $theirs = $this->getJson('/api/v1/conversations/contact-options?workspace='.$this->workspace->uuid)->assertOk()->json('data.options.0');
    expect($theirs['can_start'])->toBeFalse()
        ->and((string) $theirs['reason'])->toContain('لا يستقبل هذا المدرّس');
});

it('hides a conversation with no messages from the list unless it is the one being opened', function (): void {
    // A row left empty by the old «press to open» door.
    $empty = Conversation::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => 'private',
        'student_user_id' => $this->subscriber->getKey(),
    ]);
    DB::table('conversation_participants')->insert([
        'conversation_id' => $empty->getKey(),
        'user_id' => $this->subscriber->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($this->subscriber);
    expect($this->getJson('/api/v1/conversations')->assertOk()->json())->toBe([]);
    expect(collect($this->getJson('/api/v1/conversations?include='.$empty->uuid)->assertOk()->json())->pluck('uuid')->all())
        ->toBe([(string) $empty->uuid]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);
    expect($this->getJson('/api/v1/conversations')->assertOk()->json())->toBe([]);

    // The first message sent through the compose view lands IN the empty row.
    Sanctum::actingAs($this->subscriber);
    $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid, 'body' => 'أخيراً'])
        ->assertCreated()->assertJsonPath('uuid', (string) $empty->uuid);
});

it('throttles opening conversations per account with its own limiter', function (): void {
    Sanctum::actingAs($this->subscriber);

    foreach (range(1, 5) as $n) {
        $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid, 'body' => "رسالة {$n}"])
            ->assertCreated();
    }

    $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid, 'body' => 'السادسة'])
        ->assertStatus(429);

    // The message route beside it is a different bucket, and still open.
    $uuid = (string) contactThread($this, $this->subscriber)?->uuid;
    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'من الباب الآخر'])->assertCreated();
});

it('delivers the loser of a first-message race into the winner, losing no words', function (): void {
    $fired = false;
    $base = DB::transactionLevel();

    /*
    | The rival device inserts the thread in the instant between this request's
    | look-up and its own insert — the moment the unique index exists for. Fired
    | from the look-up itself, OUTSIDE the send's transaction: a rival written
    | inside it would roll back with the loser on this one connection.
    */
    DB::listen(function (QueryExecuted $query) use (&$fired, $base): void {
        if ($fired
            || DB::transactionLevel() !== $base
            || ! str_starts_with($query->sql, 'select * from "conversations"')
            || ! str_contains($query->sql, '"student_user_id" = ?')) {
            return;
        }

        $fired = true;

        DB::table('conversations')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->getKey(),
            'kind' => 'private',
            'student_user_id' => $this->subscriber->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    Sanctum::actingAs($this->subscriber);
    $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid, 'body' => 'من الجهاز الثاني'])
        ->assertCreated();

    expect($fired)->toBeTrue('the rival never ran — the test proved nothing');

    $threads = Conversation::query()->withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->getKey())
        ->where('student_user_id', $this->subscriber->getKey())
        ->get();

    expect($threads)->toHaveCount(1)
        ->and(Message::query()->withoutWorkspaceScope()->where('conversation_id', $threads->first()?->getKey())->pluck('body')->all())
        ->toBe(['من الجهاز الثاني']);
});

/*
| ⚠️ THE CAP'S RACE. A sequential «send four times» test is green against a build
| with no gate in it at all — the count alone refuses the fourth when the sends
| arrive one after another. The window is between the count and the insert, and
| SQLite's single connection cannot interleave two requests; so what is pinned is
| the mechanism the MySQL guarantee rests on: the gate is the transaction's first
| statement, the count comes after it, and a rival committed while this send
| waited on the gate IS counted.
*/
function prospectCapIsGate(string $sql): bool
{
    return str_starts_with($sql, 'UPDATE conversations SET id = id');
}

it('takes the conversation gate inside the transaction, then counts, then writes', function (): void {
    $uuid = contactAsProspect($this)->assertCreated()->json('uuid');

    $baseLevel = DB::transactionLevel();
    $log = [];

    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
    });

    app(PostMessage::class)->handle($this->prospect, new PostMessageData($uuid, 'الثانية'));

    $index = static function (callable $match) use ($log): ?int {
        foreach ($log as $i => $entry) {
            if ($match($entry['sql'])) {
                return $i;
            }
        }

        return null;
    };

    $gate = $index(prospectCapIsGate(...));
    $count = $index(fn (string $sql): bool => str_starts_with($sql, 'select count(*)') && str_contains($sql, '"messages"'));
    $insert = $index(fn (string $sql): bool => str_starts_with($sql, 'insert into "messages"'));

    expect($gate)->not->toBeNull('no gate statement — the count has no row to contend on')
        ->and($count)->not->toBeNull()
        ->and($insert)->not->toBeNull()
        ->and($gate)->toBeLessThan($count)
        ->and($count)->toBeLessThan($insert)
        ->and($log[$gate]['level'])->toBeGreaterThan($baseLevel)
        ->and($log[$insert]['level'])->toBe($log[$gate]['level']);
});

it('counts rival messages committed while this send waited on the gate', function (): void {
    $uuid = contactAsProspect($this)->assertCreated()->json('uuid');
    $thread = contactThread($this, $this->prospect);
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, $thread): void {
        if ($fired || ! prospectCapIsGate($query->sql)) {
            return;
        }

        $fired = true;

        // Two sends from the student's other tab, committed while this one
        // waited on the conversation's row: the thread now holds three.
        foreach (['من التبويب الآخر ١', 'من التبويب الآخر ٢'] as $body) {
            DB::table('messages')->insert([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $thread?->workspace_id,
                'conversation_id' => $thread?->getKey(),
                'sender_user_id' => $this->prospect->getKey(),
                'body' => $body,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    expect(fn () => app(PostMessage::class)->handle($this->prospect, new PostMessageData($uuid, 'الرابعة')))
        ->toThrow(DomainException::class, 'ولم يردّ المدرّس بعد');

    expect($fired)->toBeTrue('the gate never ran — the test proved nothing');
    expect(Message::query()->withoutWorkspaceScope()->where('body', 'الرابعة')->exists())->toBeFalse();
});

it('answers a deadlock on the gate with «try again», never a 500 and never the cap', function (): void {
    $uuid = contactAsProspect($this)->assertCreated()->json('uuid');

    DB::beforeExecuting(function (string $sql): void {
        if (prospectCapIsGate($sql)) {
            throw new QueryException('sqlite', $sql, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    expect(fn () => app(PostMessage::class)->handle($this->prospect, new PostMessageData($uuid, 'الثانية')))
        ->toThrow(DomainException::class, 'تعذّر إرسال الرسالة الآن. حاول مرة أخرى.');
});
