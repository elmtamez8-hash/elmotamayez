<?php

declare(strict_types=1);

use App\Modules\Community\Events\MessagePosted;
use App\Modules\Community\Models\Conversation;
use App\Modules\Learning\Actions\JoinCohort;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
| SC-015 — **الحفظُ هو المرجع، والبثُّ تحسينٌ فوقَه**.
|
| ⚠️ ولذلك يُعطَّلُ البثُّ بالكامل هنا. `MessagePosted` هو `ShouldBroadcast` لا
| `...Now`، فالنشرُ يقعُ على عاملٍ آخر — وإن سقطَ Reverb سقطَ هناك، لا في الطلبِ
| الذي كتبَ الصفّ. ما يجبُ أن يصمدَ هو أنّ الرسالةَ **تُقرأُ عندَ أوّلِ تحديث**
| والخادمُ مطفأ.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);
});

it('stores the message and reads it back with broadcasting switched off entirely', function (): void {
    /*
    | ⚠️ THE BROADCAST EVENT ALONE, NEVER A BARE `Event::fake()`. The bare form
    | swallows Eloquent's own model events with everything else — so `HasUuid`
    | never fires, the room is written with a null uuid, and the endpoint answers
    | 500 about a defect the test invented. Measured, not reasoned about: that is
    | exactly what this file did on its first run.
    */
    Event::fake([MessagePosted::class]);

    Sanctum::actingAs($this->fx['student']);

    $room = $this->getJson('/api/v1/cohorts/'.$this->fx['a']->uuid.'/chat')->assertOk()->json();

    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/messages', [
        'body' => 'هل الواجب على الفصل الثالث؟',
    ])->assertCreated();

    // The refresh: a second, independent read of the same thread.
    // No `->json('data')`: `JsonResource::withoutWrapping()` is set app-wide, so
    // the collection is the body itself.
    $messages = $this->getJson('/api/v1/conversations/'.$room['uuid'].'/messages')
        ->assertOk()
        ->json();

    expect($messages)->toHaveCount(1);
    expect($messages[0]['body'])->toBe('هل الواجب على الفصل الثالث؟');
});

/*
| ⚠️ AND THE THREAD IS THE SAME ONE FOR THE SECOND ARRIVAL. `unique(cohort_id)` is
| what makes the find-then-insert idempotent; without it two members opening the
| tab in the same second get two threads, each holding half the class, for ever
| and with nothing that throws.
*/
it('hands every member the same thread', function (): void {
    Sanctum::actingAs($this->fx['student']);

    $first = $this->getJson('/api/v1/cohorts/'.$this->fx['a']->uuid.'/chat')->assertOk()->json();
    $second = $this->getJson('/api/v1/cohorts/'.$this->fx['a']->uuid.'/chat')->assertOk()->json();

    expect($second['uuid'])->toBe($first['uuid']);

    expect(Conversation::query()
        ->withoutWorkspaceScope()
        ->where('cohort_id', $this->fx['a']->getKey())
        ->count())->toBe(1);
});
