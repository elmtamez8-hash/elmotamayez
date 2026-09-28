<?php

declare(strict_types=1);

use App\Modules\Community\Contracts\OnlineDirectory;
use App\Modules\Community\Support\ReverbOnlineDirectory;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Pusher\Pusher;

/*
| «متصل الآن» beside every name in the list (owner decision, 2026-09-28).
|
| ⚠️ THE AUTHORISATION IS THE QUESTION ITSELF. The endpoint takes no uuid: it
| asks about the other end of the reader's OWN private threads and nobody else,
| so these tests assert WHO the directory was asked about as well as what came
| back — a directory that answered for everyone would pass a test that only
| looked at the reader's own rows.
*/

/** A directory that records what it was asked and answers from a fixed set. */
final class RecordingOnlineDirectory implements OnlineDirectory
{
    /** @var list<list<string>> */
    public array $asked = [];

    /** @param  list<string>  $online */
    public function __construct(private readonly array $online) {}

    public function onlineAmong(array $userUuids): array
    {
        $this->asked[] = $userUuids;

        return array_values(array_intersect($userUuids, $this->online));
    }
}

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->classmate = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $course, $this->student);
    $this->createEnrollment($this->workspace, $course, $this->classmate);

    $open = function ($who): string {
        Sanctum::actingAs($who);

        return (string) $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid])
            ->assertCreated()
            ->json('uuid');
    };

    $this->mine = $open($this->student);
    $this->theirs = $open($this->classmate);
});

function onlineAs(object $test, $who, RecordingOnlineDirectory $directory): array
{
    app()->instance(OnlineDirectory::class, $directory);
    Sanctum::actingAs($who);

    return $test->getJson('/api/v1/conversations/online')->assertOk()->json('online');
}

it('shows a student their teacher online, and asks about nobody else', function (): void {
    $directory = new RecordingOnlineDirectory([(string) $this->teacher->uuid, (string) $this->classmate->uuid]);

    expect(onlineAs($this, $this->student, $directory))->toBe([$this->mine])
        // The classmate is online too — and was never even asked about.
        ->and($directory->asked)->toBe([[(string) $this->teacher->uuid]]);
});

it('shows the teacher which of their students are online', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $directory = new RecordingOnlineDirectory([(string) $this->classmate->uuid]);

    expect(onlineAs($this, $this->teacher, $directory))->toBe([$this->theirs]);

    $asked = $directory->asked[0];
    sort($asked);
    $expected = [(string) $this->student->uuid, (string) $this->classmate->uuid];
    sort($expected);

    expect($asked)->toBe($expected);
});

it('tells a stranger nothing, even about people who are online', function (): void {
    [$otherWorkspace, $otherTeacher] = $this->createWorkspaceWithOwner();
    $stranger = $this->addWorkspaceMember($otherWorkspace, Roles::STUDENT);

    $directory = new RecordingOnlineDirectory([
        (string) $this->teacher->uuid,
        (string) $this->student->uuid,
        (string) $this->classmate->uuid,
    ]);

    expect(onlineAs($this, $stranger, $directory))->toBe([])
        ->and($directory->asked)->toBe([]);
});

it('answers conversation uuids and nothing that names a person', function (): void {
    $directory = new RecordingOnlineDirectory([(string) $this->teacher->uuid]);
    app()->instance(OnlineDirectory::class, $directory);
    Sanctum::actingAs($this->student);

    $body = $this->getJson('/api/v1/conversations/online')->assertOk()->json();

    expect(array_keys($body))->toBe(['online'])
        ->and(json_encode($body))->not->toContain((string) $this->teacher->uuid);
});

it('reads «online» as an occupied private user channel on Reverb, filtered to the uuids asked', function (): void {
    Cache::forget('community:online-user-channels');

    $pusher = Mockery::mock(Pusher::class);
    $pusher->shouldReceive('getChannels')
        ->once()
        ->with(['filter_by_prefix' => 'private-user.'])
        ->andReturn((object) ['channels' => (object) [
            'private-user.u-1' => (object) [],
            'private-user.u-3' => (object) [],
        ]]);

    $manager = Mockery::mock(BroadcastManager::class);
    $manager->shouldReceive('connection')->andReturn(new PusherBroadcaster($pusher));

    $directory = new ReverbOnlineDirectory($manager);

    expect($directory->onlineAmong(['u-1', 'u-2']))->toBe(['u-1'])
        // Cached: a second reader in the same seconds costs Reverb nothing.
        ->and($directory->onlineAmong(['u-3']))->toBe(['u-3']);
});
