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
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionContentAccess;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StartAttempt extends Action
{
    /**
     * @throws DomainException when the student has used up the exam's attempt allowance
     */
    public function handle(Exam $exam, User $student, ?Enrollment $enrollment = null, bool $isPractice = false): Attempt
    {
        // Resolve the enrollment when the caller didn't supply one, otherwise the
        // ExamPassed → certificate chain has nothing to attach the certificate to.
        $enrollment ??= $this->enrollmentFor($exam, $student);

        if (! $isPractice) {
            $this->guardAttemptLimit($exam, $student);
        }

        $this->guardSessionContent($exam, $student);

        // exam_attempts.random_seed is an unsignedInteger column — keep the seed
        // inside 32 bits or strict-mode MySQL rejects the insert.
        $seed = random_int(1, 2147483647);

        return DB::transaction(function () use ($exam, $student, $enrollment, $seed, $isPractice): Attempt {
            $attempt = Attempt::create([
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

        return Enrollment::query()
            ->where('course_id', $exam->course_id)
            ->where('student_user_id', $student->getKey())
            ->first();
    }

    /**
     * @throws DomainException
     */
    private function guardAttemptLimit(Exam $exam, User $student): void
    {
        $used = Attempt::query()
            ->where('exam_id', $exam->getKey())
            ->where('student_user_id', $student->getKey())
            // ⚠️ PRACTICE RUNS DO NOT SPEND OFFICIAL ATTEMPTS (FR-026أ). Without
            // this line the first revision session eats a graded chance, so the
            // feature that exists to help a student prepare is the feature that
            // stops them sitting the exam.
            ->where('is_practice', false)
            ->count();

        if ($used >= $exam->max_attempts) {
            throw new DomainException('You have used all '.$exam->max_attempts.' attempts for this exam.');
        }
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
