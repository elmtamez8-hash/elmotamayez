<?php

declare(strict_types=1);

namespace App\Modules\Learning\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Support\LessonAccess;
use App\Modules\Learning\Support\LessonGate;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Learning\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property string $status
 * @property string $source
 * @property-read Course $course course_id is NOT NULL, so the relation always resolves
 * @property-read User $student
 */
class Enrollment extends BaseModel
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'course_id',
        'student_user_id',
        'source',
        'order_id',
        'status',
        'progress_pct',
        'enrolled_at',
        'completed_at',
        'expires_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'progress_pct' => 'integer',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    /*
    | ⛔ **بلا نطاقٍ على العلاقة، وغيابُه كانَ ٥٠٠ لا صفحةً ناقصة.**
    |
    | العلاقةُ تجري تحتَ `WorkspaceScope` كأيِّ استعلامٍ آخر، و`WorkspaceContext::id()`
    | يرجعُ إلى `users.last_workspace_id` — المختومِ لكلِّ طالبٍ أُضيفَ يوماً إلى
    | مساحةِ عمل. فترجعُ `null` عن كورسٍ قائمٍ، ثمّ ينفجرُ `->title` أو `->lessons()`
    | فوقَها: المنهجُ ٥٠٠، و«تعلّمي» ٥٠٠، وإشعارُ التسجيلِ يقتلُ التسجيلَ نفسَه.
    |
    | ⚠️ **ولا يفتحُ هذا باباً**: الوصولُ إلى هذا الصفِّ محروسٌ فوقَه (مِلكيّةٌ أو
    | سياسة)، والكورسُ هنا هو الكورسُ الذي يُشيرُ إليه المفتاحُ الأجنبيُّ لا كورسٌ
    | يختارُه القارئ. وهي القاعدةُ المكتوبةُ في CLAUDE.md: التجاوزُ **لكلِّ نموذجٍ
    | على حِدة**، و`->with('course')` يُعيدُ تشغيلَ نطاقِ الكورسِ داخلَ العلاقة.
    */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    /** @return HasMany<LessonProgress, $this> */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * هل يفتحُ هذا التسجيلُ محتوى الكورسِ لصاحبِه؟
     *
     * ⚠️ **`isActive()` وحدَها كانت الشرطَ في البوّابة، فكانَ إتمامُ الكورسِ
     * يُغلِقُه.** الحالةُ تصيرُ `completed` لحظةَ إتمامِ آخرِ درس، فيردُّ
     * {@see LessonGate} «تسجيلك في هذا الكورس غير نشط حالياً» على **كلِّ** درسٍ
     * فيه — أي أنّ جائزةَ إنهاءِ الكورسِ كانت فقدانَه. وشاشةُ «تعلّمي» تعرضُ
     * للمنتهي زرَّ «راجعِ الكورس»، وهو زرٌّ يقودُ إلى منهجٍ مقفولٍ بالكامل.
     * قِيسَ على الإنتاجِ ٢٠٢٦-٠٩-١٤: تسجيلٌ واحدٌ في هذه الحالةِ فعلاً.
     *
     * ⚠️ **وهجاءٌ واحدٌ لأنّ البوّابةَ تسألُ في موضعَين** — `for()` للعنصرِ
     * الواحدِ و`forTree()` للشجرةِ كلِّها — وتعليقُ ذلك الملفِّ يقولُ صراحةً إنّ
     * الاثنَينِ «يجبُ أن يتحرّكا معاً». شرطٌ يُوسَّعُ في أحدِهما دونَ الآخرِ
     * يفتحُ المنهجَ ويقفلُ الدرسَ، أو العكس.
     *
     * ⚠️ ولا يشملُ ما عداهما: عمودُ الحالةِ نصٌّ حرٌّ، فأيُّ قيمةٍ أخرى
     * (إيقافٌ أو إلغاء) تبقى ممنوعةً بالبناءِ لا بقائمةِ استثناءات.
     */
    public function grantsContentAccess(): bool
    {
        return $this->isActive() || $this->isCompleted();
    }

    /**
     * Returns all lessons of the course ordered by section → chapter → lesson order.
     *
     * ⚠️ THE EAGER LOAD IS NOT AN OPTIMISATION HERE. `LessonGate::forTree()` asks
     * `isVisibleChain()` of every row, and that calls `loadMissing(['section',
     * 'chapter'])` — so without this the curriculum of a 200-lesson course is 400
     * extra queries, growing with the tree. That is exactly what `SC-004` and
     * `CurriculumQueryBudgetTest` forbid.
     *
     * ⛔ **AND EVERY READ HERE BYPASSES `WorkspaceScope`, ON THE RELATION AND
     * INSIDE THE EAGER LOAD BOTH — WITHOUT IT THE COURSE IS EMPTY.**
     * `course()` above carries the bypass; `Course::lessons()` is a fresh query
     * on `Lesson` and runs the scope again, and `WorkspaceContext::id()` falls
     * back to `users.last_workspace_id` — stamped on every student a teacher, an
     * invitation or a seeder ever added to a workspace (six rows with role
     * `student` measured on a real database). So the whole curriculum came back
     * with **zero lessons** for those students: a 200, no error, a course they
     * paid for reading as if nothing had been written in it.
     *
     * ⚠️ **And the eager load is the second half, not a detail.** A scoped
     * `->with(['section','chapter'])` returns null for both, and `loadMissing`
     * inside `isVisibleChain()` then finds them «already loaded» and does not
     * refetch — so a published lesson reads as invisible and is dropped by a
     * guard that was written correctly. An eager load that overwrites a relation
     * a guard means to fetch with a bypass is worse than no eager load at all.
     *
     * @return Collection<int, Lesson>
     */
    public function orderedLessons(): Collection
    {
        return $this->course->lessons()
            ->withoutWorkspaceScope()
            ->with([
                'section' => fn ($query) => $query->withoutGlobalScope(WorkspaceScope::class),
                'chapter' => fn ($query) => $query->withoutGlobalScope(WorkspaceScope::class),
            ])
            ->join('course_sections', 'lessons.section_id', '=', 'course_sections.id')
            ->join('course_chapters', 'lessons.chapter_id', '=', 'course_chapters.id')
            ->orderBy('course_sections.order')
            ->orderBy('course_chapters.order')
            ->orderBy('lessons.order')
            ->select('lessons.*')
            ->get();
    }

    /**
     * Whether the student can open this item — the bool form.
     *
     * Kept because a dozen call sites ask it as one. The reasoning, and the
     * documentation of how it is answered, live on `accessTo()` below.
     */
    public function canAccessLesson(Lesson $lesson): bool
    {
        return $this->accessTo($lesson)->allowed;
    }

    /**
     * The same decision, carrying its reason (FR-043).
     *
     * ⚠️ ONE LINE ON PURPOSE. The body moved to {@see LessonGate::for()} unchanged
     * so that the curriculum screen, which needs the same answer for two hundred
     * rows at once, can be built ON this decision rather than beside it. A bulk
     * computation written next to a single-row one is the two-spellings defect
     * this repository has already paid for three times — most sharply in the
     * recording that `IssuePlaybackGrant` allowed while this method refused.
     *
     * The signature and every caller are untouched: a dozen call sites ask this,
     * and the extraction is a no-op for all of them.
     */
    public function accessTo(Lesson $lesson): LessonAccess
    {
        return LessonGate::for($this, $lesson);
    }
}
