<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مكانُ طالبٍ في دَورِ كورسٍ مكتمل (٠٣٤ · FR-026 … FR-029).
 *
 * ⚠️ **ولا يحجزُ مقعداً ولا يَعِدُ به** (FR-027). الصفُّ يقولُ «أعلِمْني إن
 * فُتِحَ مكان» ولا شيءَ غيرَ ذلك — ولذلك لا عمودَ موضعٍ فيه ولا سعةَ ولا تاريخَ
 * انتهاء: ترتيبُه مشتقٌّ من وقتِ إنشائِه، والشاشةُ تقولُ ذلك بالنصّ.
 *
 * ⚠️ **والتصنيفُ بالمساحةِ لا يحرسُ الطالب.** صاحبُ الصفِّ عضوٌ في لا مساحة،
 * فـ`WorkspaceScope` خاملٌ على كلِّ مسارٍ يصلُه — الحراسةُ شرطٌ صريحٌ بصاحبِ
 * الصفِّ في الفعلِ نفسِه. والسمةُ هنا لأنّ اللوحةَ تقرأُ الجدولَ بالمساحة،
 * ولأنّ جدولاً مملوكاً لمستأجِرٍ بلا سمةٍ يُسرِّبُ بينَ المساحاتِ ولا اختبارَ
 * يراه.
 *
 * @property-read Course $course course_id is NOT NULL, so the relation always resolves
 *
 * ⚠️ و`student` مُعلَنٌ غيرَ نَوّالٍ والعمودُ بلا مفتاحٍ أجنبيّ: القرّاءُ يشترطونَ
 * `whereHas('student')` على الاستعلام — صفٌّ لا يُسمّي أحداً يُسقِطُ الطلبَ كلَّه
 * لا صفَّه، وهو ما قِيسَ في `ListStudentBalances`.
 * @property-read User $student
 */
class CourseWaitlistEntry extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $table = 'course_waitlist_entries';

    /*
    | ⚠️ `closed_slot` و`invited_at` و`closed_at` ليسَت قابلةً للإسناد: كلٌّ
    | منها يُكتَبُ داخلَ التحديثِ الشرطيِّ الذي يملكُ الانتقال — الدعوةُ في
    | {@see \App\Modules\Learning\Actions\InviteFromWaitlist} والخروجُ في
    | {@see \App\Modules\Learning\Listeners\LeaveWaitlistOnEnrolment}. ومسنَدةً
    | تصيرُ باباً ثانياً إلى الانتقالِ من خارجِ الجملةِ التي تملكُه، وهي قاعدةُ
    | `orders.captured_order_id` نفسُها.
    */
    protected $fillable = [
        'workspace_id',
        'course_id',
        'student_user_id',
        'registered_by_user_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
