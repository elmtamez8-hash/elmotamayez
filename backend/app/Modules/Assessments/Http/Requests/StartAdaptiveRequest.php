<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which concept, at which teacher (spec 012 · FR-001).
 *
 * ⚠️ NEITHER UUID CARRIES AN `exists` RULE, deliberately — `BuildSelfExamRequest`'s
 * reason exactly. Laravel's `exists` is a raw query with no tenant condition, so
 * it answers «does this concept exist anywhere on the platform», which is a yes/no
 * oracle over every other teacher's taxonomy for anyone who can loop. The Action
 * resolves both inside the workspace the student is actually enrolled at.
 */
class StartAdaptiveRequest extends FormRequest
{
    /**
     * ⚠️ NO PERMISSION CHECK, AND THAT IS THE FIX RATHER THAN THE OMISSION.
     * spatie runs in team mode and a student is a member of no workspace, so the
     * team id is null and EVERY `can()` for them is false — a permission here
     * would answer 403 to every real student, which is exactly what
     * `POST /practice/exams` did until 2026-08-27. The authorisation is the
     * active enrolment, which the Action reads to pick the questions.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'concept' => ['required', 'uuid'],
            // Required here and optional on the generated paper: this endpoint
            // reads a per-workspace feature switch, and «which workspace» cannot
            // be a guess when the answer decides a 403.
            'teacher' => ['required', 'uuid'],
        ];
    }
}
