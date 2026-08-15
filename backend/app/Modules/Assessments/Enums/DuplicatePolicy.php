<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

/**
 * What an import does with a row that already exists in the bank.
 *
 * ⚠️ Chosen AT UPLOAD, never asked about later. The import runs in a queued job
 * (FR-008) and by then the teacher has closed the tab — a job that stops to ask
 * a question is a job that hangs for ever. The report names every skipped row by
 * its line number instead, which is the answer they would have given anyway.
 *
 * ⚠️ `Skip` IS A LOOKUP, AND THAT IS A CORRECTION. It was designed as a database
 * constraint — `unique(workspace_id, content_hash)` — because reading first and
 * then inserting is the definition of the race two uploads win together. That
 * index could not be created: real workspaces already hold questions sharing a
 * text, written into different exams back when a question BELONGED to one, and
 * `exam_answers.question_id` points at each of them from real attempts. They are
 * legitimate rows and merging them would orphan or rewrite graded papers.
 *
 * So the race is closed where it actually happens instead: `WithoutOverlapping`
 * on the workspace serialises imports, and the lookup runs inside that lock. A
 * constraint the existing rows cannot satisfy is not a stronger guarantee — it
 * is a deploy that fails.
 */
enum DuplicatePolicy: string
{
    case Skip = 'skip';
    case Create = 'create';
}
