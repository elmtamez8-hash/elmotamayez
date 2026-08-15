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
];
