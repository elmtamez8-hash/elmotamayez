<?php

declare(strict_types=1);

return [
    /*
    | How many non-practice answers a question needs before a wrong-answer rate
    | is stated at all (FR-013).
    |
    | The fallback for a database with nothing seeded — the live value is a row
    | in `platform_settings` an operator edits from the panel, on the precedent
    | set in 006: a threshold that can only change by shipping code is a
    | threshold nobody ever tunes.
    |
    | Five, because the number this guards is one a teacher acts on by DELETING a
    | question. Below it the rate is null and the screen says so; it is never
    | zero, which would read as "nobody got this wrong" for a question two people
    | happened to sit.
    */
    'min_sample_size' => 5,

    /*
    | The adaptive path (spec 012 · FR-007). Four numbers an operator tunes from
    | the panel; the live values are `platform_settings` rows and these are the
    | fallback for a database with nothing seeded — never a second source.
    |
    | `promote_after`  — consecutive correct answers before the next question is
    |                    drawn one step harder. Two, because one is noise: a
    |                    single lucky guess should not move a student to hard.
    | `mastery_correct`— correct answers AT THE CEILING before a concept is
    |                    called mastered. ⚠️ The ceiling is the highest difficulty
    |                    that concept actually HAS for this student, not `hard`:
    |                    a concept authored entirely at `easy` must be masterable,
    |                    or it enters the student's list and can never leave it.
    | `max_questions`  — the hard stop on one session, so a tab left open cannot
    |                    walk the whole bank.
    | `start_difficulty` — where a session with no history begins.
    */
    'adaptive' => [
        'promote_after' => 2,
        'mastery_correct' => 3,
        'max_questions' => 20,
        'start_difficulty' => 'easy',
    ],
];
