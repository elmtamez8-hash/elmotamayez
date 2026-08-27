<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Support\LessonAccess;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CurriculumFixtures;

uses(CurriculumFixtures::class);

/*
| `GET /courses/{course}/curriculum` — the tree with a state on every row and a
| reason on every closed one (US1 · FR-004 · FR-008 · FR-010).
|
| ⚠️ THE POINT OF THE SCREEN IS THAT THE STUDENT LEARNS THE ANSWER BEFORE THEY
| PRESS. Today they discover a lock by tapping a row, waiting, and reading a
| refusal on a page they cannot use — and the reason lives at the door rather
| than beside the item. So the assertions here are about what the PAYLOAD says,
| not about a status code: a 200 carrying `state: "open"` on a locked item is the
| defect, and it is invisible to any test that only checks the request succeeded.
*/

/** Every lesson in the response, flattened and keyed by uuid. */
function curriculumRows(array $payload): array
{
    $rows = [];

    foreach ($payload['sections'] as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                $rows[$lesson['uuid']] = $lesson;
            }
        }
    }

    return $rows;
}

beforeEach(function (): void {
    $this->tree = $this->curriculumTree();

    Sanctum::actingAs($this->tree['student']);
    $this->forgetWorkspace();
});

it('names the reason on every closed row, with the code a screen can switch on', function (): void {
    $payload = $this->getJson('/api/v1/courses/'.$this->tree['course']->uuid.'/curriculum')
        ->assertOk()
        ->json();

    $rows = curriculumRows($payload);
    $lessons = $this->tree['lessons'];

    $stateOf = fn (string $key): string => $rows[$lessons[$key]->uuid]['state'];
    $codeOf = fn (string $key): ?string => $rows[$lessons[$key]->uuid]['lock']['code'] ?? null;

    expect($stateOf('done'))->toBe('completed')
        ->and($stateOf('exam_ok'))->toBe('completed')
        ->and($rows[$lessons['done']->uuid]['lock'])->toBeNull()
        ->and($stateOf('open'))->toBe('open')
        ->and($stateOf('sequence'))->toBe('locked')
        ->and($stateOf('preview'))->toBe('open');

    // The four refusals a student can meet on this screen, each by its code.
    expect($codeOf('sequence'))->toBe(LessonAccess::SEQUENCE)
        ->and($codeOf('no_seat'))->toBe(LessonAccess::NO_SEAT)
        ->and($codeOf('exam_pass'))->toBe(LessonAccess::EXAM_PASS)
        ->and($codeOf('exam_attempt'))->toBe(LessonAccess::EXAM_ATTEMPT);

    /*
     | ⚠️ AND THE SENTENCE NAMES THE ITEM TO GO AND DO. «مقفول» with nothing after
     | it is a support ticket — and an exam gate is invisible from the locked row,
     | because what has to happen is on another page entirely.
     */
    expect($rows[$lessons['sequence']->uuid]['lock']['blocked_by_title'])->toBe('الدرس الثاني')
        ->and($rows[$lessons['sequence']->uuid]['lock']['message'])->toContain('الدرس الثاني')
        ->and($rows[$lessons['exam_pass']->uuid]['lock']['message'])->toContain('اختبار النجاح');
});

/*
| FR-004. That a teacher has an unfinished lesson at this position is the
| teacher's business — and a «قريباً» row invites a student to keep trying the URL.
|
| ⚠️ ASSERTED ON THE DECODED PAYLOAD, NEVER ON `getContent()`. That method escapes
| everything outside ASCII, so `->assertDontSee('مسوّدة')` is true of a response
| that carries the draft's title in full. Every exposure assertion in this product
| is about Arabic text, which is why the whole family has to be read this way.
*/
it('removes a draft and an archived item from the tree rather than showing them closed', function (): void {
    $payload = $this->getJson('/api/v1/courses/'.$this->tree['course']->uuid.'/curriculum')
        ->assertOk()
        ->json();

    $rows = curriculumRows($payload);

    expect($rows)->not->toHaveKey($this->tree['lessons']['draft']->uuid)
        ->and($rows)->not->toHaveKey($this->tree['lessons']['archived']->uuid);

    // The control: the ten visible items ARE there, so the assertion above is not
    // passing over an empty tree.
    expect($rows)->toHaveCount(10);

    // `not_visible` is a decision, never a payload — and `not_enrolled` makes the
    // whole request a 403, so neither code can reach a row.
    foreach ($rows as $row) {
        expect($row['lock']['code'] ?? null)
            ->not->toBe(LessonAccess::NOT_VISIBLE)
            ->not->toBe(LessonAccess::NOT_ENROLLED);
    }
});

it('points «تابعْ من هنا» at the first open item that is not finished', function (): void {
    $payload = $this->getJson('/api/v1/courses/'.$this->tree['course']->uuid.'/curriculum')
        ->assertOk()
        ->json();

    // Not the first row (finished) and not the exam item before it (finished by
    // its attempt): the first thing there is actually left to do.
    expect($payload['course']['resume_lesson_uuid'])->toBe($this->tree['lessons']['open']->uuid);
});

it('carries the course cover, the teacher and both counts', function (): void {
    $payload = $this->getJson('/api/v1/courses/'.$this->tree['course']->uuid.'/curriculum')
        ->assertOk()
        ->json('course');

    expect($payload['cover_url'])->toContain('storage/courses/cover.jpg')
        ->and($payload['teacher_name'])->toBe($this->tree['workspace']->name)
        ->and($payload['is_sequential'])->toBeTrue()
        // The denominator excludes the recording, the preview's own type aside:
        // eight countable articles and exams, one of them done.
        ->and($payload['countable_count'])->toBeGreaterThan(0)
        ->and($payload['completed_count'])->toBe(2);
});

/*
| ⚠️ AN EXPIRED ENROLMENT STILL RENDERS THE TREE — AND EVERY ROW IN IT CARRIES A
| LOCK, THE FINISHED ONES INCLUDED.
|
| `accessTo()` never asks whether the TARGET is complete, so a lesson the student
| finished last month is refused with `inactive` once the enrolment lapses. A
| payload that let completion null the lock would send «مكتمل» to the screen as a
| link, the student would tap it, and the door would refuse — one answer beside
| the item and another behind it, produced by the screen built to end exactly
| that. The preview stays open, which is what a preview is for.
*/
it('locks a finished item too when the enrolment has lapsed, and keeps the preview open', function (): void {
    $this->tree['enrollment']->update(['status' => 'expired']);

    $rows = curriculumRows(
        $this->getJson('/api/v1/courses/'.$this->tree['course']->uuid.'/curriculum')->assertOk()->json(),
    );

    $done = $rows[$this->tree['lessons']['done']->uuid];

    expect($done['state'])->toBe('completed')
        ->and($done['lock']['code'])->toBe(LessonAccess::INACTIVE)
        ->and($rows[$this->tree['lessons']['open']->uuid]['lock']['code'])->toBe(LessonAccess::INACTIVE)
        ->and($rows[$this->tree['lessons']['preview']->uuid]['lock'])->toBeNull()
        ->and($rows[$this->tree['lessons']['preview']->uuid]['state'])->toBe('open');
});

it('refuses a signed-in stranger with 403 rather than pretending the course is gone', function (): void {
    // A `404` would break the buy button beside the message: the reader is
    // authenticated and this is a course they could enrol in.
    Sanctum::actingAs(User::factory()->create());
    $this->forgetWorkspace();

    $this->getJson('/api/v1/courses/'.$this->tree['course']->uuid.'/curriculum')
        ->assertForbidden()
        ->assertJsonPath('code', LessonAccess::NOT_ENROLLED);
});
