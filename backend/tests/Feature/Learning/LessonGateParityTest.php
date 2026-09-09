<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\LessonAccess;
use App\Modules\Learning\Support\LessonGate;
use Tests\Support\CurriculumFixtures;

uses(CurriculumFixtures::class);

/*
| ⚠️ THE ONLY GUARD AGAINST TWO ANSWERS TO ONE QUESTION.
|
| `LessonGate::for()` decides whether one item opens; `forTree()` decides it for
| two hundred at once, because a curriculum screen cannot ask per row. The
| tempting shape — a bulk computation written BESIDE the single-row one — is the
| defect this repository has paid for in `BookingEligibility` (a host told they
| were not enrolled with themselves), in `ListLeaderboardScopes` (boards offered
| that the API refused), and most sharply in the recording `IssuePlaybackGrant`
| allowed while `accessTo()` refused, which made a paid-for lesson unreachable.
|
| So this file asserts the two agree on `allowed` AND on `code`, for every item
| of a tree that reaches all six refusals. Nothing else can see the drift: each
| form's own tests are individually correct while they disagree.
|
| ⚠️ AND IT ASSERTS THE SIX ARE PRESENT. A parity test over a fixture that only
| exercises the sequence path passes on a bulk form with no exam branch, no seat
| branch and no visibility branch in it at all — the same "green because of the
| wrong condition" shape spec 013's US6 shipped nine times.
*/

/**
 * @param  array<string, Lesson>  $lessons
 * @return array<string, string> key → the code `for()` answered with, or 'allowed'
 */
function parityCodes(Enrollment $enrollment, array $lessons): array
{
    $single = [];
    $bulk = LessonGate::forTree($enrollment);

    foreach ($lessons as $key => $lesson) {
        $one = LessonGate::for($enrollment, $lesson->fresh());
        $many = $bulk[(int) $lesson->getKey()] ?? null;

        expect($many)->not->toBeNull("forTree() answered nothing for «{$lesson->title}»");
        expect($many->allowed)->toBe($one->allowed, "«{$lesson->title}»: forTree() says ".var_export($many->allowed, true).' and for() says '.var_export($one->allowed, true));
        expect($many->code)->toBe($one->code, "«{$lesson->title}»: forTree() code «{$many->code}» ≠ for() code «{$one->code}»");
        expect($many->message)->toBe($one->message, "«{$lesson->title}»: the two forms word the same refusal differently");
        expect($many->blockedByTitle)->toBe($one->blockedByTitle);

        $single[$key] = $one->allowed ? 'allowed' : (string) $one->code;
    }

    return $single;
}

it('answers every item of a tree exactly as the single-item gate does', function (): void {
    ['enrollment' => $enrollment, 'lessons' => $lessons] = $this->curriculumTree();

    $codes = parityCodes($enrollment, $lessons);

    // ⚠️ The parity assertions above are vacuous over a fixture that never
    // reaches a branch. This is what makes them bite.
    expect($codes)->toBe([
        'done' => 'allowed',
        'exam_ok' => 'allowed',
        'open' => 'allowed',
        'sequence' => LessonAccess::SEQUENCE,
        'draft' => LessonAccess::NOT_VISIBLE,
        'archived' => LessonAccess::NOT_VISIBLE,
        'preview' => 'allowed',
        'no_seat' => LessonAccess::NO_SEAT,
        'exam_failed' => LessonAccess::SEQUENCE,
        'exam_pass' => LessonAccess::EXAM_PASS,
        'exam_untouched' => LessonAccess::SEQUENCE,
        'exam_attempt' => LessonAccess::EXAM_ATTEMPT,
    ]);
});

/*
| ⚠️ THE ORDER OF THE FIRST FOUR CONDITIONS IS SEMANTICS, NOT STYLE.
|
| `is_preview` is asked AFTER the visibility chain and BEFORE the enrolment
| status. A bulk form that hoisted the status check — the natural thing to do,
| since it is one boolean for the whole tree — would close the preview items of
| every expired enrolment, which is the one thing a preview exists to keep open.
*/
it('keeps preview open on an inactive enrolment, and closes everything else', function (): void {
    ['enrollment' => $enrollment, 'lessons' => $lessons] = $this->curriculumTree();

    $enrollment->update(['status' => 'expired']);
    $enrollment->refresh();

    $codes = parityCodes($enrollment, $lessons);

    expect($codes['preview'])->toBe('allowed')
        ->and($codes['draft'])->toBe(LessonAccess::NOT_VISIBLE)
        ->and($codes['archived'])->toBe(LessonAccess::NOT_VISIBLE)
        ->and($codes['done'])->toBe(LessonAccess::INACTIVE)
        ->and($codes['open'])->toBe(LessonAccess::INACTIVE)
        ->and($codes['no_seat'])->toBe(LessonAccess::INACTIVE);
});

/*
| A course with no sequence at all: every published item opens, and the recording
| still does not — the seat is a different question from the order.
*/
it('agrees on a non-sequential course, where only the seat still refuses', function (): void {
    ['enrollment' => $enrollment, 'lessons' => $lessons] = $this->curriculumTree();

    $enrollment->course->update(['is_sequential' => false]);
    $enrollment->load('course');

    $codes = parityCodes($enrollment, $lessons);

    expect($codes['sequence'])->toBe('allowed')
        ->and($codes['exam_pass'])->toBe('allowed')
        ->and($codes['exam_attempt'])->toBe('allowed')
        ->and($codes['draft'])->toBe(LessonAccess::NOT_VISIBLE)
        ->and($codes['archived'])->toBe(LessonAccess::NOT_VISIBLE)
        ->and($codes['no_seat'])->toBe(LessonAccess::NO_SEAT);
});

/*
| ⛔ `is_free` IS THE SAME QUESTION AS `is_preview`, AND THE GATE READ ONLY ONE
| HALF OF IT UNTIL 2026-09-09.
|
| Spec 032's third open decision. `Lesson::isOpen()` is `is_preview || is_free`
| and `isPubliclyReadable()` is built on it, so an `is_free` embedded lesson was
| offered to a STRANGER off the public course page while this gate — the only
| surface in the product that plays a lesson — refused it to the enrolled student
| whose term had lapsed. Two doors, disagreeing, and the video opening only from
| the one that was never meant to carry it. That is spec 018's recording defect
| reached from a new direction.
|
| ⚠️ THE INACTIVE ENROLMENT IS THE LOAD-BEARING HALF OF THIS CASE. On an ACTIVE
| enrolment a non-sequential free item opens anyway, so a test written that way
| passes against a gate with no `is_free` in it at all — green over exactly the
| bug. The refusal has to be the one `isOpen()` is meant to jump.
*/
it('opens an is_free item on an inactive enrolment, in BOTH forms', function (): void {
    ['enrollment' => $enrollment, 'lessons' => $lessons] = $this->curriculumTree();

    // Marked free rather than preview — the half that was not being read.
    $lessons['sequence']->update(['is_free' => true, 'is_preview' => false]);

    $enrollment->update(['status' => 'expired']);
    $enrollment->refresh();

    $codes = parityCodes($enrollment, $lessons);

    expect($codes['sequence'])->toBe('allowed')
        // ⚠️ And the control: everything NOT open still shuts on the same
        // enrolment, or «allowed» above would just be a gate that stopped
        // refusing anything.
        ->and($codes['done'])->toBe(LessonAccess::INACTIVE)
        ->and($codes['open'])->toBe(LessonAccess::INACTIVE)
        ->and($codes['draft'])->toBe(LessonAccess::NOT_VISIBLE);
});
