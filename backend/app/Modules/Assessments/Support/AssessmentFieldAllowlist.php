<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

/**
 * What a student-facing assessment payload may carry, and what it may never.
 *
 * On the shape of `PublicFieldAllowlist` and `StudentBalanceAllowlist`: a list
 * in one place, walked by one test, so that adding a field to a Resource fails
 * the build rather than a review.
 *
 * ⚠️ AND THE THREE MOST DANGEROUS PROHIBITIONS IN SPEC 008 ARE NOT IN THIS
 * CLASS, because a field list cannot express them. «Another student's answer»,
 * «a classmate's submission» and «somebody else's accommodation» are ROW-level
 * rules: this list passes `score` and `answer_text` without knowing whose row
 * they came from, so a payload returning the WRONG ROW with entirely correct
 * FIELDS walks through it green. Those three live in `AssessmentExposureTest`'s
 * second half — "student B asks for student A's resource", on every route that
 * takes a uuid — and this paragraph exists because a guard described without
 * bounds is read as coverage.
 *
 * ⚠️ AN ACCOMMODATION IS EXPOSED BY ITS EFFECT, NOT BY ITS NAME (FR-056). A
 * classmate who sees `state: on_time` on a submission handed in after `due_at`
 * has been told there is an extension, and a longer attempt timer sorts the
 * beneficiaries out of any shared screen. So `state`, `submitted_at`,
 * `extension_until` and an attempt's expiry are owner-private — allowed on your
 * own row, a leak on anybody else's. That is again a row rule, and again the
 * adversarial test is what enforces it.
 */
class AssessmentFieldAllowlist
{
    /**
     * The complete option shape a student is handed while sitting a paper.
     *
     * ⚠️ THE PAPER'S ANSWERS ARE ONE FORGOTTEN `->only()` AWAY FROM THE PAPER.
     * `QuestionOption` carries `is_correct`, so serialising the model — the
     * obvious way to write this — hands every sitting student the mark scheme
     * inside the same response that asks them the question. `AttemptController`
     * maps the two fields by hand for that reason, and this list is what fails
     * if a third ever joins them.
     *
     * @return list<string>
     */
    public static function sitOptionFields(): array
    {
        return ['id', 'content'];
    }

    /**
     * The complete shape of a question served by the adaptive path (spec 012).
     *
     * ⚠️ THE EXACT KEY SET, ASSERTED WITH `toBe()`. The failure this guards ADDS
     * a key rather than removing one — serialising the frozen snapshot instead of
     * mapping it out of it, which hands over `correct_option_ids` and
     * `explanation` inside the response that asks the question. A test that only
     * checked the expected keys were present would pass against exactly that.
     *
     * @return list<string>
     */
    public static function adaptiveQuestionFields(): array
    {
        return ['question_id', 'order', 'content', 'points', 'difficulty', 'options'];
    }

    /**
     * Never present while a paper is open, whatever the payload.
     *
     * ⚠️ THESE ARE LEGITIMATE AFTER SUBMISSION AND ONLY THEN — `PracticeResult`
     * and the mistake notebook exist to show exactly these fields, which is why
     * they cannot be forbidden outright. The window is the rule, not the name.
     *
     * @return list<string>
     */
    public static function forbiddenDuringAttempt(): array
    {
        return [
            'is_correct',
            'correct_answer',
            'correct_option_ids',
            'explanation',
        ];
    }

    /**
     * Never present in a student-facing payload at any moment.
     *
     * Internal keys plus the two that are a leak by construction: a stored file
     * path is a url that outlives the grant FR-048 puts on it, and any field
     * naming an accommodation announces one.
     *
     * @return list<string>
     */
    public static function forbidden(): array
    {
        return [
            'workspace_id',
            'student_user_id',
            'graded_by',
            'grader_user_id',
            'file_path',
            'file_url',
            'has_accommodation',
            'accommodation',
            'extra_time_pct',
        ];
    }
}
