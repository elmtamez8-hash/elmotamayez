<?php

declare(strict_types=1);

namespace App\Modules\Learning\Listeners;

use App\Modules\Learning\Events\EnrollmentCreated;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * مَن صارَ له تسجيلٌ يخرجُ من الدَّور — من أيِّ بابٍ جاءَ التسجيل (٠٣٤ · FR-028).
 *
 * ⚠️ **على الحدثِ لا عندَ القراءة.** الشكلُ البديهيُّ استثناءٌ وقتَ قراءةِ
 * الدَّور («كلُّ من ليسَ مسجَّلاً») — وهو **استعلامٌ فرعيٌّ مرتبطٌ في كلِّ فتحِ
 * صفحة**، وأسوأُ من ذلك: **الصفُّ لا يُختَمُ أبداً**، فيفقدُ `closed_at` معناه
 * ولا يبقى في الجدولِ شيءٌ يقولُ متى خرجَ أحدٌ ولا لماذا. والدعوةُ تقرأُ الجدولَ
 * نفسَه، فاستثناءٌ منسيٌّ في أحدِ القارئَينِ يدعو مَن يملكُ مقعدَه أصلاً.
 *
 * ⚠️ **وتحديثٌ شرطيٌّ واحد، يخدمُه الفهرسُ الفريدُ بالضبط.** `closed_slot` يصيرُ
 * معرِّفَ الصفِّ نفسِه — فريداً بالتعريف — فيخرجُ الصفُّ من مدى الفهرسِ
 * `(student, course, 0)` ويستطيعُ الطالبُ أن يصطفَّ ثانيةً لو انتهى تسجيلُه
 * يوماً. وشرطُ `closed_slot = 0` هو ما يجعلُ حدثاً مكرَّراً لا يكتبُ شيئاً.
 *
 * ⚠️ **و`DB::table()` لا النموذج**: `closed_at`/`closed_slot` ليسا قابلَينِ
 * للإسنادِ عمداً — الانتقالُ ملكُ الجملةِ التي تكتبُه — وتحديثٌ جمليٌّ لا يسترجعُ
 * نماذجَ أصلاً، فلا شيءَ من طبقةِ النموذجِ يجري هنا. وهي قاعدةُ
 * `LedgerEntry` من بابٍ آخر.
 */
class LeaveWaitlistOnEnrolment implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function handle(EnrollmentCreated $event): void
    {
        $enrollment = $event->enrollment;

        DB::table('course_waitlist_entries')
            ->where('course_id', $enrollment->course_id)
            ->where('student_user_id', $enrollment->student_user_id)
            ->where('closed_slot', 0)
            ->update([
                'closed_at' => now(),
                'closed_slot' => DB::raw('id'),
                'updated_at' => now(),
            ]);
    }
}
