<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * `variables` in the order the BODY reads them, on rows that already exist.
 *
 * ⚠️ ORDER IS THE WHOLE MEANING ON WHATSAPP. `WhatsAppChannel` sends `variables`
 * as the body's numbered parameters, in their declared order — and three lists
 * did not follow their body: `credit_balance_dormant` would have put the months
 * where the credits belong, `session_report` the lesson where the child's name
 * belongs, `periodic_review_published` the child where the teacher belongs. None
 * has been submitted to the provider yet, so correcting the list now costs
 * nothing; after approval it would have been wrong on a parent's phone.
 *
 * ⚠️ AND `certificate_issued` NEVER REQUIRED THE TITLE ITS OWN BODY PRINTS, so a
 * certificate whose course was gone rendered «عن «»». It is required now; the
 * listener refuses and logs instead.
 *
 * ⚠️ ONLY `variables`, AND ONLY WHERE IT STILL EQUALS WHAT SHIPPED. Every row is
 * editable from `/admin`: the title and body are never touched here, and a list
 * an operator already changed is left as they left it — `seedMissing()` exists
 * for the same reason. The seeder writes the new order, so a fresh database
 * agrees with a migrated one (`TemplateVariableOrderTest`).
 *
 * By the model, not `DB::table()`: the column is cast to an array, and comparing
 * the decoded list is what makes «still equals what shipped» exact.
 */
return new class extends Migration
{
    /** type => [shipped list, corrected list] */
    private const CORRECTIONS = [
        'credit_balance_dormant' => [
            ['course', 'credits', 'months'],
            ['course', 'months', 'credits'],
        ],
        'session_report' => [
            ['title', 'student_name', 'status', 'minutes', 'note'],
            ['student_name', 'title', 'status', 'minutes', 'note'],
        ],
        'periodic_review_published' => [
            ['student_name', 'teacher_name', 'period_start', 'period_end'],
            ['teacher_name', 'student_name', 'period_start', 'period_end'],
        ],
        'certificate_issued' => [
            ['name', 'certificate_number'],
            ['name', 'certificate_number', 'course_title'],
        ],
    ];

    public function up(): void
    {
        foreach (self::CORRECTIONS as $type => [$shipped, $corrected]) {
            // Every channel of the type — the in-app row and the WhatsApp row
            // share one list.
            $rows = MessageTemplate::query()->where('type', $type)->get();

            foreach ($rows as $row) {
                if ($row->variables !== $shipped) {
                    continue;
                }

                $row->forceFill(['variables' => $corrected])->save();
            }
        }
    }

    /** Empty on purpose — the shipped order was the defect. */
    public function down(): void {}
};
