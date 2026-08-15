<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Enums\BloomLevel;
use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Enums\ImportStatus;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionImport;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads a CSV of questions into the bank, and reports on every row that did not
 * make it.
 *
 * ⚠️ ONE BAD ROW DOES NOT STOP THE FILE (FR-007). A teacher exporting a thousand
 * questions from an old system will have ten with a typo, and an all-or-nothing
 * import hands them back nothing plus a message about line 47. Each row is its
 * own unit of success; the failures are collected and named.
 *
 * ⚠️ AND IT IS DELIBERATELY NOT WRAPPED IN ONE TRANSACTION. That is the same
 * statement from the database's side: a transaction around the loop means the
 * last row's failure discards the 999 that worked.
 *
 * No CSV library. `fgetcsv` is PHP's own and handles quoting, embedded newlines
 * and separators inside quotes — the three things a hand-written splitter gets
 * wrong. What it does not do is strip the byte-order mark, and that is handled
 * below by hand because it is three lines.
 */
class ImportQuestions extends Action
{
    use LogsActivity;

    /** The header, in the order the template gives it. */
    public const COLUMNS = ['content', 'concept', 'difficulty', 'bloom_level', 'type', 'points', 'explanation', 'options', 'correct'];

    private const BOM = "\xEF\xBB\xBF";

    /** Options are one cell, separated by this. A pipe survives Arabic text and Excel. */
    private const OPTION_SEPARATOR = '|';

    public function __construct(
        private readonly SaveQuestion $saveQuestion,
    ) {}

    public function handle(QuestionImport $import): QuestionImport
    {
        try {
            $this->run($import);
        } catch (Throwable $exception) {
            // A file that is not a CSV at all produces no rows to report, so the
            // reason belongs to the import rather than to a line.
            $import->update([
                'status' => ImportStatus::Failed,
                'failure_reason' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $import->refresh();
    }

    private function run(QuestionImport $import): void
    {
        $handle = $this->open($import);

        $header = $this->readHeader($handle);
        $concepts = $this->conceptIndex((int) $import->workspace_id);

        // Two rows with the same text INSIDE one file are duplicates of each
        // other, and the policy applies to them exactly as it applies against the
        // table. Without this the first two lines of a file both insert, and the
        // teacher who chose "skip" gets the copy they asked us to prevent.
        $seen = [];

        $report = [];
        // Counted separately from $line, which numbers rows for the report and so
        // has to keep counting the blank ones. Deriving the total from it would
        // make a file with trailing blank lines report a total that does not
        // equal imported + skipped + failed.
        $rows = 0;
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($this->isBlank($row)) {
                continue;
            }

            $rows++;

            try {
                $outcome = $this->importRow($import, $header, $row, $concepts, $seen);
            } catch (Throwable $exception) {
                $failed++;
                $report[] = [
                    'line' => $line,
                    'reason' => $exception->getMessage(),
                    'content' => $this->preview($header, $row),
                ];

                continue;
            }

            if ($outcome === null) {
                $skipped++;
                $report[] = [
                    'line' => $line,
                    'reason' => 'سؤالٌ بالنصّ نفسه موجود في البنك — تُخطّي بحسب السياسة المختارة.',
                    'content' => $this->preview($header, $row),
                ];

                continue;
            }

            $imported++;
        }

        fclose($handle);

        $import->update([
            'status' => ImportStatus::Done,
            'total_rows' => $rows,
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'report' => $report,
            'finished_at' => now(),
        ]);

        $this->logActivity('questions_imported', $import, [
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $header  column name → position
     * @param  array<string, int>  $concepts  concept name → id
     * @param  array<string, true>  $seen  content hashes already inserted by THIS file
     * @return Question|null null means skipped by policy
     */
    private function importRow(QuestionImport $import, array $header, array $row, array &$concepts, array &$seen): ?Question
    {
        $content = trim($this->cell($header, $row, 'content'));

        if ($content === '') {
            throw new DomainException('نصّ السؤال فارغ.');
        }

        $hash = Question::hashOf($content);

        if ($import->duplicate_policy === DuplicatePolicy::Skip && $this->exists($import, $hash, $seen)) {
            return null;
        }

        $question = $this->saveQuestion->createInBank(
            (int) $import->workspace_id,
            [
                'concept_id' => $this->conceptId($import, $this->cell($header, $row, 'concept'), $concepts),
                'type' => $this->type($this->cell($header, $row, 'type')),
                'difficulty' => $this->difficulty($this->cell($header, $row, 'difficulty')),
                'bloom_level' => $this->bloom($this->cell($header, $row, 'bloom_level'))->value,
                'content' => $content,
                'points' => max(1, (int) ($this->cell($header, $row, 'points') ?: 1)),
                'explanation' => $this->cell($header, $row, 'explanation') ?: null,
            ],
            $this->options($header, $row),
        );

        $seen[$hash] = true;

        return $question;
    }

    /**
     * Is this question already here?
     *
     * ⚠️ A LOOKUP, AND SAFE ONLY BECAUSE OF THE LOCK AROUND IT. There is no
     * unique index on `(workspace_id, content_hash)` — live data could not
     * satisfy one, see {@see DuplicatePolicy}. `ImportQuestionsJob` carries
     * `WithoutOverlapping` keyed on the workspace, so two uploads cannot be
     * inside this method at once.
     *
     * What the lock does NOT cover is somebody typing the same question into the
     * bank screen while an import runs. That is accepted: the live data already
     * proves same-text questions are legitimate rows, and the cost of the race is
     * one duplicate a teacher can disable — not a corrupted ledger.
     *
     * @param  array<string, true>  $seen
     */
    private function exists(QuestionImport $import, string $hash, array $seen): bool
    {
        if (isset($seen[$hash])) {
            return true;
        }

        return Question::query()
            ->where('workspace_id', $import->workspace_id)
            ->where('content_hash', $hash)
            ->exists();
    }

    /** @return resource */
    private function open(QuestionImport $import)
    {
        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($import->stored_path)) {
            throw new DomainException('تعذّر العثور على الملف المرفوع.');
        }

        $handle = fopen($disk->path($import->stored_path), 'r');

        if ($handle === false) {
            throw new DomainException('تعذّر فتح الملف المرفوع.');
        }

        return $handle;
    }

    /**
     * The header row, mapped to positions — and stripped of its byte-order mark.
     *
     * ⚠️ EXCEL WRITES A BOM ON EVERY CSV IT EXPORTS. Those three bytes sit in
     * front of the first cell, so `'content'` arrives as `"\xEF\xBB\xBFcontent"`
     * and matches nothing. The failure is invisible in every editor and produces
     * the same message on every row of every file the teacher exports from Excel:
     * "نصّ السؤال فارغ".
     *
     * @param  resource  $handle
     * @return array<string, int>
     */
    private function readHeader($handle): array
    {
        $row = fgetcsv($handle);

        if ($row === false) {
            throw new DomainException('الملف فارغ.');
        }

        $header = [];

        foreach ($row as $index => $name) {
            $name = strtolower(trim(str_replace(self::BOM, '', (string) $name)));

            if ($name !== '') {
                $header[$name] = $index;
            }
        }

        foreach (['content', 'concept', 'difficulty', 'bloom_level'] as $required) {
            if (! isset($header[$required])) {
                throw new DomainException("العمود المطلوب «{$required}» غير موجود في الملف.");
            }
        }

        return $header;
    }

    /**
     * Concepts by name, loaded once.
     *
     * A per-row lookup would be one SELECT per line — a thousand queries for a
     * file with four concepts in it.
     *
     * @return array<string, int>
     */
    private function conceptIndex(int $workspaceId): array
    {
        /** @var array<string, int> $index */
        $index = Concept::query()
            ->where('workspace_id', $workspaceId)
            ->pluck('id', 'name')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        return $index;
    }

    /**
     * The concept named in this row, creating it if the teacher has not yet.
     *
     * Created rather than refused: a file of five hundred questions across six
     * concepts would otherwise fail five hundred times over six names the
     * teacher would have typed anyway. The empty cell is different — it is a row
     * with no concept at all, and FR-002 refuses it.
     *
     * @param  array<string, int>  $concepts
     */
    private function conceptId(QuestionImport $import, string $name, array &$concepts): int
    {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('عمود الفكرة فارغ، والوسم إلزامي.');
        }

        if (isset($concepts[$name])) {
            return $concepts[$name];
        }

        $concept = Concept::create([
            'workspace_id' => $import->workspace_id,
            'name' => $name,
            'created_by' => $import->uploaded_by,
        ]);

        $concepts[$name] = (int) $concept->getKey();

        return $concepts[$name];
    }

    /**
     * The options cell into rows, with the correct ones marked.
     *
     * `options` holds them separated by a pipe; `correct` holds the 1-based
     * positions of the right answers, also piped. Positions rather than repeated
     * text: an answer key that repeats the option's words breaks on a trailing
     * space nobody can see.
     *
     * @param  array<string, int>  $header
     * @param  array<int, string|null>  $row
     * @return array<int, array{content: string, is_correct: bool, order: int}>
     */
    private function options(array $header, array $row): array
    {
        $raw = trim($this->cell($header, $row, 'options'));

        if ($raw === '') {
            return [];
        }

        $correct = array_filter(array_map(
            static fn (string $value): int => (int) trim($value),
            explode(self::OPTION_SEPARATOR, $this->cell($header, $row, 'correct')),
        ));

        $options = [];

        foreach (explode(self::OPTION_SEPARATOR, $raw) as $index => $content) {
            $content = trim($content);

            if ($content === '') {
                continue;
            }

            $options[] = [
                'content' => $content,
                'is_correct' => in_array($index + 1, $correct, true),
                'order' => $index + 1,
            ];
        }

        if ($options !== [] && ! in_array(true, array_column($options, 'is_correct'), true)) {
            // The same rule the form enforces: a question nobody can get right
            // is not a hard question, it is a broken one, and its 100% wrong rate
            // reads as the hardest item in the teacher's bank.
            throw new DomainException('لم يُحدَّد أيّ خيارٍ صحيح في عمود «correct».');
        }

        return $options;
    }

    private function type(string $value): string
    {
        $value = strtolower(trim($value)) ?: 'mcq';

        return in_array($value, ['mcq', 'true_false', 'essay'], true)
            ? $value
            : throw new DomainException("نوع سؤالٍ غير معروف: «{$value}».");
    }

    private function difficulty(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['easy', 'medium', 'hard'], true)
            ? $value
            : throw new DomainException("مستوى صعوبةٍ غير معروف: «{$value}».");
    }

    private function bloom(string $value): BloomLevel
    {
        return BloomLevel::tryFrom(strtolower(trim($value)))
            ?? throw new DomainException("مستوى معرفيٍّ غير معروف: «{$value}».");
    }

    /**
     * @param  array<string, int>  $header
     * @param  array<int, string|null>  $row
     */
    private function cell(array $header, array $row, string $column): string
    {
        $index = $header[$column] ?? null;

        return $index === null ? '' : (string) ($row[$index] ?? '');
    }

    /** @param  array<int, string|null>  $row */
    private function isBlank(array $row): bool
    {
        return $row === [null] || trim(implode('', array_map(static fn (?string $c): string => (string) $c, $row))) === '';
    }

    /**
     * Enough of the row for the teacher to find it in their spreadsheet.
     *
     * @param  array<string, int>  $header
     * @param  array<int, string|null>  $row
     */
    private function preview(array $header, array $row): string
    {
        return mb_substr(trim($this->cell($header, $row, 'content')), 0, 120);
    }
}
