<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Assessments\Support\ApplyAccommodation;
use App\Modules\Assessments\Support\QuestionSnapshot;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonAudience;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\LessonAccess;
use App\Modules\Learning\Support\LessonGate;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionContentAccess;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StartAttempt extends Action
{
    /**
     * @throws DomainException when the student has used up the exam's attempt
     *                         allowance, when the exam's item in the course is
     *                         still locked by the sequence, or when a second tap
     *                         lost the claim on the same attempt
     */
    public function handle(Exam $exam, User $student, ?Enrollment $enrollment = null, bool $isPractice = false): Attempt
    {
        // Resolve the enrollment when the caller didn't supply one, otherwise the
        // submission has no enrolment to move progress on.
        $enrollment ??= $this->enrollmentFor($exam, $student);

        $attemptNumber = $isPractice ? null : $this->guardAttemptLimit($exam, $student);

        $this->guardSessionContent($exam, $student);

        if (! $isPractice && $enrollment !== null) {
            $this->guardSequence($exam, $student, $enrollment);
        }

        // exam_attempts.random_seed is an unsignedInteger column — keep the seed
        // inside 32 bits or strict-mode MySQL rejects the insert.
        $seed = random_int(1, 2147483647);

        try {
            return $this->createAttempt($exam, $student, $enrollment, $seed, $isPractice, $attemptNumber);
        } catch (UniqueConstraintViolationException) {
            /*
            | ⛔ THE CLAIM WAS LOST, AND THAT IS THE ONLY THING THIS CATCH MEANS.
            | `unique(exam_id, student_user_id, attempt_number)` is the attempt
            | allowance's guard: two taps both counted the same number of used
            | attempts and both asked for the same `attempt_number`, and the
            | database gave it to one of them. Caught as the unique violation
            | SPECIFICALLY — a `QueryException` here would turn every other
            | failure (a null, a foreign key, a column out of range) into «you
            | already started», which is the objection R7 wrote down for
            | `CreditLedger`.
            */
            throw new DomainException('بدأتَ محاولةً لهذا الاختبار للتوّ — حدِّث الصفحة لتكملها.');
        }
    }

    /**
     * @throws UniqueConstraintViolationException when another start claimed the same attempt number
     */
    private function createAttempt(Exam $exam, User $student, ?Enrollment $enrollment, int $seed, bool $isPractice, ?int $attemptNumber): Attempt
    {
        return DB::transaction(function () use ($exam, $student, $enrollment, $seed, $isPractice, $attemptNumber): Attempt {
            $attempt = Attempt::create([
                // The claim. This insert is the first statement of the
                // transaction, so a lost claim leaves nothing behind — the frozen
                // items below are never written.
                'attempt_number' => $attemptNumber,
                // Take the workspace from the exam, not the ambient context: an actor
                // operating globally (Super Admin with no current workspace) would
                // otherwise write a NULL workspace_id.
                'workspace_id' => $exam->workspace_id,
                'exam_id' => $exam->getKey(),
                'enrollment_id' => $enrollment?->getKey(),
                'student_user_id' => $student->getKey(),
                'status' => 'in_progress',
                'is_practice' => $isPractice,
                // FR-054 — the accommodation applies by itself or it is not an
                // accommodation. Frozen here rather than derived on read: a
                // withdrawal must not re-time a paper already sat.
                'duration_minutes' => app(ApplyAccommodation::class)->effectiveDuration(
                    (int) $exam->workspace_id,
                    (int) $student->getKey(),
                    $exam->duration_minutes,
                ),
                'random_seed' => $seed,
                'started_at' => now(),
            ]);

            $this->freezeItems($attempt, $exam);

            return $attempt;
        });
    }

    /**
     * ٠٣٥ · FR-008 — an exam that belongs to a session is part of that session.
     *
     * ⛔ AND THE LINK IS THROUGH `lessons`, BECAUSE `exams` CARRIES NO
     * `class_session_id` AT ALL. Measured: the column exists on `assignments` and
     * on `unlock_rules` and nowhere else in this module. An exam is reached from
     * a lesson whose `reference_id` names it, and that lesson is what carries the
     * session — so the join is the only spelling available and inventing a column
     * would be a second answer to a question the tree already answers.
     *
     * ⚠️ ASKED HERE AND NOT ONLY IN THE CURRICULUM PAYLOAD. A screen that hides a
     * button is not a guard: this Action is the single entry point the seeder,
     * the panel and the API all share, which is the rule the whole module is
     * built on.
     */
    private function guardSessionContent(Exam $exam, User $student): void
    {
        /*
        | ⛔ ٠٢٦ — **صفُّ الشجرةِ كلُّه لا `class_session_id` وحدَه.** الفحصُ
        | كانَ يقرأُ العمودَ بـ`value()`، فلم يكنْ يبلغُ سطراً واحداً من أسطرِ
        | «لمن هذا العنصرُ ومتى يظهر»: اختبارٌ مقصورٌ على مجموعةٍ أو مربوطٌ
        | بحصّةٍ لم تُعقَدْ لا يحملُ حصّةً لِيَحمِلَها هذا العمود.
        */
        $lesson = Lesson::query()
            // الطالبُ سياقُه فارغٌ أو مساحةٌ أخرى، والنطاقُ يُرجِعُ صفراً له.
            ->withoutWorkspaceScope()
            ->referencing(LessonType::Exam->value, (int) $exam->getKey())
            ->first();

        if ($lesson === null) {
            return;
        }

        /*
        | ٠٣٥ · FR-008 — محتوى الحصّةِ أوّلاً، بترتيبِ `LessonGate::for()` نفسِه:
        | بابانِ يسألانِ السؤالَينِ بترتيبَينِ مختلفَينِ جوابانِ مختلفانِ لحالةٍ
        | واحدة.
        */
        if ($lesson->class_session_id !== null
            && ! app(SessionContentAccess::class)->mayOpenSessionContent($student, (int) $lesson->class_session_id)) {
            throw new DomainException('محتوى هذه الحصة مقفول — افتحه بخصم حصة من رصيدك.');
        }

        /*
        | ⛔ ٠٢٦ · FR-011 — **الحكمُ واحدٌ لا فرعٌ لكلِّ رمز**، و`LessonAudience`
        | يستثني المؤلّفَ بنفسِه فلا يُستثنى هنا ثانية.
        |
        | ⚠️ **والجملةُ لا تُفشي شيئاً.** الصفُّ مُسقَطٌ من المنهجِ ومن فهرسِ
        | الاختبارات، فمَن بلغَ هذا السطرَ إنّما طرَقَ المعرّفَ مباشرةً — و«هذا
        | لمجموعةٍ أخرى» تُخبِرُه بوجودِ ورقةٍ ما كانَ ليعرفَها، و«افتحه بخصمِ
        | حصّة» تعليمةٌ تدلُّه على بابٍ سيُرفَضُ عندَه.
        */
        if (LessonAudience::hiddenFor($student, $lesson) !== null) {
            throw new DomainException('هذا الاختبار غير متاح حالياً.');
        }
    }

    /**
     * Write what the student is being shown, now, before they can answer.
     *
     * ⚠️ AT START AND NOT AT SUBMIT. "What they saw" is defined at the moment the
     * paper is handed over — an edit landing mid-attempt does not change the paper
     * somebody is already holding (FR-004).
     *
     * And it fixes the denominator at the same instant: it used to be recomputed
     * from the exam's live questions at grading time, so a question deleted while
     * a student was working moved their total underneath them.
     */
    private function freezeItems(Attempt $attempt, Exam $exam): void
    {
        $items = $exam->items()->with('question.options')->get();

        foreach ($items as $index => $item) {
            $question = $item->question;

            AttemptItem::create([
                'workspace_id' => $attempt->workspace_id,
                'attempt_id' => $attempt->getKey(),
                'question_id' => $question->getKey(),
                'order' => $item->order !== 0 ? $item->order : $index + 1,
                'points' => $item->effectivePoints(),
                // Built by the shared builder, because BuildPracticeFromMistakes
                // writes the same array and GradeAttempt reads one key out of it.
                'snapshot' => QuestionSnapshot::of($question),
            ]);
        }
    }

    /**
     * The student's enrollment in the exam's course, if the exam belongs to one.
     */
    private function enrollmentFor(Exam $exam, User $student): ?Enrollment
    {
        if ($exam->course_id === null) {
            return null;
        }

        // Unscoped: the student's context may be another teacher's workspace
        // (`users.last_workspace_id`), which would hide this enrolment and write
        // the attempt with no `enrollment_id`.
        return Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $exam->course_id)
            ->where('student_user_id', $student->getKey())
            ->first();
    }

    /**
     * The attempt number this start will claim, or a refusal.
     *
     * ⛔ THE COUNT IS A PRE-CHECK AND THE UNIQUE INDEX IS THE GUARD. Counting and
     * then inserting is a read followed by a write, and a double tap on «ابدأ»
     * put two workers through the count together: with `max_attempts = 1` both
     * read zero and both inserted — two graded attempts at a paper allowed one.
     * The number returned here is written into `attempt_number`, and
     * `unique(exam_id, student_user_id, attempt_number)` lets exactly one of the
     * two taps have it; `handle()` turns the loser into a sentence.
     *
     * ⚠️ `max(count, highest) + 1`, not `count + 1`. An official attempt that
     * was deleted (erasure, a teacher's reset) lowers the count but not the
     * highest number already taken, and `count + 1` would then collide with a
     * live row on every start — a student refused for ever with attempts left.
     *
     * @throws DomainException
     */
    private function guardAttemptLimit(Exam $exam, User $student): int
    {
        // ⛔ Unscoped, or a stamped student's count reads ZERO and the limit
        // never bites — unlimited attempts at a graded paper.
        $row = Attempt::query()
            ->withoutWorkspaceScope()
            ->where('exam_id', $exam->getKey())
            ->where('student_user_id', $student->getKey())
            // ⚠️ PRACTICE RUNS DO NOT SPEND OFFICIAL ATTEMPTS (FR-026أ). Without
            // this line the first revision session eats a graded chance, so the
            // feature that exists to help a student prepare is the feature that
            // stops them sitting the exam.
            ->where('is_practice', false)
            ->toBase()
            ->selectRaw('count(*) as used, max(attempt_number) as highest')
            ->first();

        $used = (int) ($row->used ?? 0);
        $highest = (int) ($row->highest ?? 0);

        if ($used >= $exam->max_attempts) {
            throw new DomainException('استنفدتَ عددَ المحاولاتِ المسموحِ به لهذا الاختبار ('.$exam->max_attempts.').');
        }

        return max($used, $highest) + 1;
    }

    /**
     * The course's sequence, asked at the exam's door as it is asked at the item.
     *
     * ⛔ `POST /exams/{uuid}/attempts` NEVER ASKED IT. An exam placed as an item
     * in a sequential course is locked on the curriculum until the items before
     * it are done — `LessonGate::for()`, the one gate the lesson page, the
     * curriculum and the completion door all read — but the exam's own endpoint
     * took the uuid and started a graded attempt. So a student skipped straight to
     * the final exam, spent an attempt, and on a pass ticked the item that the
     * sequence was holding shut. A screen that hides a button is not a guard.
     *
     * The gate is REUSED, never re-derived: two spellings of «may this student
     * open this item» is the defect this repository has paid for in
     * `BookingEligibility`, `ListLeaderboardScopes` and spec 018's recording.
     *
     * ⚠️ EVERY PLACEMENT, NOT THE FIRST. One exam may sit in a tree twice under
     * two gates, so the student may start it when ANY of its placements in their
     * course is open to them. A placement hidden from the student
     * (`LessonAccess::hidesRow()`: a draft, another group's item, an unreleased
     * or unheld session) is not a placement in their course: it gates nothing,
     * and its sentence is never the one they read.
     *
     * ⚠️ THE AUTHOR IS EXEMPT, and the predicate is the pivot ROLE in the EXAM'S
     * workspace — not `teachesOnPlatform()`, which is platform-wide and would
     * exempt a teacher enrolled as a student at another teacher's course. It is
     * `LessonAudience::exemptAuthor()`'s predicate.
     *
     * @throws DomainException
     */
    private function guardSequence(Exam $exam, User $student, Enrollment $enrollment): void
    {
        // No eager loads: `isVisibleChain()` fetches the parents with its own
        // bypass, and a scoped preload would be found «already loaded» and
        // defeat it.
        $placements = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $enrollment->course_id)
            ->referencing(LessonType::Exam->value, (int) $exam->getKey())
            ->get();

        if ($placements->isEmpty() || $this->authorsIn($student, (int) $exam->workspace_id)) {
            return;
        }

        $refusal = null;

        foreach ($placements as $lesson) {
            $access = LessonGate::for($enrollment, $lesson);

            if ($access->allowed) {
                return;
            }

            // A placement hidden from this student (a draft, another group's
            // item, an unreleased or unheld session) is not in their tree, and
            // its sentence must never be the one they read — the same codes the
            // curriculum drops rather than words.
            if (LessonAccess::hidesRow($access->code)) {
                continue;
            }

            $refusal ??= $access;
        }

        if ($refusal !== null) {
            throw new DomainException($refusal->message ?? 'هذا الاختبار مقفول حتى تُكمل ما قبله.');
        }
    }

    private function authorsIn(User $user, int $workspaceId): bool
    {
        return DB::table('workspace_members')
            ->where('user_id', $user->getKey())
            ->where('workspace_id', $workspaceId)
            ->where('role', '!=', Roles::STUDENT)
            ->exists();
    }

    /**
     * Returns the questions for this attempt, optionally randomized using the
     * attempt's stored seed (reproducible).
     *
     * @return Collection<int, Question>
     */
    public function questionsForAttempt(Attempt $attempt): Collection
    {
        // ⚠️ THROUGH THE FROZEN ITEMS, NOT THROUGH THE LIVE EXAM. Reading the exam
        // means a question added or removed mid-attempt changes the paper in the
        // student's hands, and a question deleted after the fact makes the review
        // of a graded attempt impossible to render.
        $questions = $attempt->items()
            ->with('question.options')
            ->get()
            ->map(fn (AttemptItem $item) => $item->question)
            ->filter()
            ->values();

        if ($attempt->exam === null || ! $attempt->exam->shuffle_questions) {
            return $questions;
        }

        // Seeded Fisher-Yates shuffle (reproducible via the stored seed).
        $seed = $attempt->random_seed;
        mt_srand($seed);
        $shuffled = $questions->values()->all();
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
        }
        mt_srand();

        return collect($shuffled);
    }

    /**
     * Returns the options for a question, optionally shuffled with the attempt's seed.
     *
     * @param  Collection<int, QuestionOption>  $options
     * @return Collection<int, QuestionOption>
     */
    public function optionsForQuestion(Attempt $attempt, Collection $options): Collection
    {
        if ($attempt->exam === null || ! $attempt->exam->shuffle_answers) {
            return $options;
        }

        // Vary the seed per question so two questions of the same attempt don't
        // shuffle their options identically.
        $seed = $attempt->random_seed + ($options->isEmpty() ? 0 : $options->first()->question_id);
        mt_srand($seed);
        $shuffled = $options->values()->all();
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
        }
        mt_srand();

        return collect($shuffled);
    }
}
