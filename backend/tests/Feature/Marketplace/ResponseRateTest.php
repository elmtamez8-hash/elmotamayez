<?php

declare(strict_types=1);

use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Marketplace\Jobs\SyncResponseRatesJob;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;

/*
| «معدّل الاستجابة» had readers and no writer, so every real teacher showed «—»
| (owner report 2026-10-11). These pin what one «wait» is and who it is held against.
*/

function responseRow(int $conversation, bool $staff, string $at): object
{
    return (object) ['conversation_id' => $conversation, 'from_staff' => $staff, 'created_at' => $at];
}

it('counts one wait per run of student messages, answered within a day or not', function (): void {
    $now = CarbonImmutable::parse('2026-10-11 12:00');

    $rows = [
        // Answered after 2h — the follow-up while waiting is the same wait.
        responseRow(1, false, '2026-10-01 10:00'), responseRow(1, false, '2026-10-01 10:05'), responseRow(1, true, '2026-10-01 12:00'),
        // Answered after 30h: late.
        responseRow(1, false, '2026-10-02 10:00'), responseRow(1, true, '2026-10-03 16:00'),
        // Three more answered in time, in other threads.
        responseRow(2, false, '2026-10-04 10:00'), responseRow(2, true, '2026-10-04 11:00'),
        responseRow(3, false, '2026-10-05 10:00'), responseRow(3, true, '2026-10-05 10:30'),
        responseRow(4, false, '2026-10-06 10:00'), responseRow(4, true, '2026-10-06 20:00'),
        // Never answered, and older than a day: counts against.
        responseRow(5, false, '2026-10-07 10:00'),
        // Sent an hour ago: not judged yet.
        responseRow(6, false, '2026-10-11 11:00'),
        // A staff message with nobody waiting judges nothing.
        responseRow(7, true, '2026-10-08 10:00'),
    ];

    // 6 judged waits, 4 answered in time.
    expect(SyncResponseRatesJob::rate($rows, $now))->toBe(67);
});

it('shows no figure until enough waits have been judged', function (): void {
    $now = CarbonImmutable::parse('2026-10-11 12:00');

    expect(SyncResponseRatesJob::rate([responseRow(1, false, '2026-10-01 10:00')], $now))->toBeNull()
        ->and(SyncResponseRatesJob::rate([], $now))->toBeNull();
});

it('writes each workspace its own rate and leaves a quiet one at no figure', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$quiet, $quietOwner] = $this->createWorkspaceWithOwner();

    $profile = TeacherProfile::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'response_rate' => 95]);
    $quietProfile = TeacherProfile::factory()->create(['workspace_id' => $quiet->id, 'user_id' => $quietOwner->id, 'response_rate' => 95]);

    $this->setCurrentWorkspace($workspace, $owner);

    foreach (range(1, 5) as $i) {
        $conversation = Conversation::factory()->create();
        $asked = now()->subDays(10 + $i);

        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $conversation->student_user_id, 'from_staff' => false])
            ->forceFill(['created_at' => $asked])->save();

        // Four answered within the hour, the fifth two days later.
        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $owner->id, 'from_staff' => true])
            ->forceFill(['created_at' => $i === 5 ? $asked->addDays(2) : $asked->addHour()])->save();
    }

    (new SyncResponseRatesJob)->handle();

    expect($profile->fresh()->response_rate)->toBe(80)
        ->and($quietProfile->fresh()->response_rate)->toBeNull();
});
