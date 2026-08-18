<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Assessments\Actions\SaveQuestion;
use App\Modules\Assessments\Enums\BloomLevel;
use App\Modules\Assessments\Models\Question;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One request class for create and edit, and the edit takes the WHOLE question.
 *
 * ⚠️ A PATCH HERE IS A REPLACEMENT, NOT A MERGE. Half the payload's fields carry
 * a meaningful "absent" — `lesson_id`, `explanation`, `is_active`, and the whole
 * options list — so a partial update cannot tell "leave the lesson alone" from
 * "detach it" without a second vocabulary nobody would remember to use. The edit
 * screen holds every field already, so it sends every field; this is the same
 * choice reordering a course tree made when it required the complete sibling list.
 *
 * The tags are validated here AND enforced in {@see SaveQuestion}.
 * That is not redundancy: the importer and the seeder reach the Action with no
 * request behind them, and FR-002 is a property of the question, not of the form.
 */
class SaveQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $question = $this->route('question');

        if ($question instanceof Question) {
            return $this->user()?->can('update', $question) ?? false;
        }

        return $this->user()?->can(Permissions::QUESTIONS_MANAGE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // uuid, never the autoincrement id — and scoped, because Laravel's
            // bare `exists` is a raw query that no global scope reaches.
            'concept_id' => ['required', 'uuid', WorkspaceRules::exists('concepts', 'uuid')],
            'lesson_id' => ['nullable', 'uuid', WorkspaceRules::exists('lessons', 'uuid')],
            // `text` from before spec 008 is deliberately not offered: it has no
            // options to mark and no essay screen to grade, so every attempt at
            // one scores zero for ever.
            'type' => ['required', 'string', 'in:mcq,true_false,essay'],
            'difficulty' => ['required', 'string', 'in:easy,medium,hard'],
            'bloom_level' => ['required', Rule::enum(BloomLevel::class)],
            'content' => ['required', 'string', 'max:20000'],
            'points' => ['required', 'integer', 'min:1', 'max:1000'],
            'explanation' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['nullable', 'boolean'],

            // An essay has no options; anything else needs at least two and at
            // least one right answer, checked below because no per-field rule can
            // see the list as a whole.
            'options' => ['nullable', 'array', 'max:20'],
            'options.*.content' => ['required_with:options', 'string', 'max:5000'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->string('type')->value() === 'essay') {
                return;
            }

            /** @var array<int, array<string, mixed>> $options */
            $options = (array) $this->input('options', []);

            if (count($options) < 2) {
                $validator->errors()->add('options', 'يحتاج السؤال خيارين على الأقل.');

                return;
            }

            /*
             * ⚠️ EXACTLY ONE, AND «AT LEAST ONE» WAS THE RULE UNTIL A TEACHER TICKED
             * TWO BOXES AND ASKED WHY THAT WAS ALLOWED.
             *
             * Neither end of it is a matter of taste. A question with NO correct
             * option is not a hard question, it is a question every student gets
             * wrong: the grader scores zero, the mistake notebook records it against
             * everybody, and a wrong_pct of 100% reads as the hardest item in the
             * bank. And TWO correct options is the same defect wearing the opposite
             * face — `GradeAttempt::matchesSnapshot()` compares the SETS, so the
             * student must select both to score, while every answering surface in
             * this product is single-select. One tap can never satisfy it, so again
             * every student is wrong for ever, and again it reads as the hardest
             * question the teacher owns.
             *
             * The three offered types are `mcq`, `true_false` and `essay`; not one of
             * them means "choose all that apply". A question that did would need its
             * own type, its own student UI and its own partial-credit rule — a
             * feature, not a checkbox left unbounded.
             */
            $correct = array_filter($options, static fn (array $option): bool => (bool) ($option['is_correct'] ?? false));

            if (count($correct) !== 1) {
                $validator->errors()->add('options', 'حدِّد إجابةً صحيحةً واحدةً بالضبط.');
            }
        });
    }
}
