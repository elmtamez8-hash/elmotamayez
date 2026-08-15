<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Step 7c. Fill `uuid` and `student_user_id` on every answer already recorded.
|
| chunkById for the same reason as step 3: the predicate shrinks under an OFFSET
| walk, and the command reports success while skipping most of the table.
|
| The student is read from the parent attempt. This is the one and only write to
| the duplicated column — after this, `GradeAttempt` sets it at insert time and
| nothing ever updates it, which is why the duplication cannot drift.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('exam_answers')
            ->select('exam_answers.id', 'exam_attempts.student_user_id')
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_answers.attempt_id')
            ->orderBy('exam_answers.id')
            ->chunkById(500, function ($answers): void {
                foreach ($answers as $answer) {
                    DB::table('exam_answers')->where('id', $answer->id)->update([
                        'uuid' => (string) Str::uuid(),
                        'student_user_id' => $answer->student_user_id,
                    ]);
                }
            }, 'exam_answers.id', 'id');
    }

    public function down(): void
    {
        // The columns go with step 7b's down(); blanking them here would only
        // widen the window where the data is gone but the schema is not.
    }
};
