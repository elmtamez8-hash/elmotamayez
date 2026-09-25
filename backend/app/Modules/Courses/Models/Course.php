<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Exceptions\CourseDeletionRefused;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPubliclyListed;
use App\Shared\Traits\IsPublishable;
use Database\Factories\Modules\Courses\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;

/**
 * @property string $status
 * @property string $visibility
 * @property string $course_type
 * @property string|null $cover_path
 * @property int $price_minor
 * @property int|null $price_before_discount_minor
 * @property int|null $private_session_minutes
 * @property string|null $promo_video_id
 * @property string $promo_video_status
 * @property Carbon|null $promo_video_reviewed_at
 * @property int|null $promo_video_reviewed_by
 * @property Carbon|null $last_delivered_at
 * @property Carbon|null $created_at
 * @property-read User|null $creator created_by is nullable — a course can outlive its author
 * @property-read Subject|null $subject subject_id is nullable — 007 added the column with no writer,
 *   and a course created before 026's backfill (or by a factory) still carries none
 */
class Course extends BaseModel
{
    /** @use HasFactory<CourseFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid, IsPubliclyListed, IsPublishable, Searchable, SoftDeletes;

    /*
    | ⛔ A COURSE SOMEBODY BOUGHT IS NEVER DELETED — AND THIS HOOK IS THE ONLY
    | PLACE THAT SAYS SO, BECAUSE IT IS THE ONLY PLACE EVERY DOOR REACHES.
    |
    | `enrollments.course_id` and `orders.course_id` carry no foreign key, so a
    | deleted course used to leave its enrolments pointing at nothing, and
    | `EnrollmentResource` read `$this->course->uuid` on null: one deleted course
    | and «تعلّمي» answered 500 for EVERY one of its buyers.
    |
    | Three doors delete a course — `CourseController::destroy`, the panel's
    | `DeleteAction` and its `DeleteBulkAction` — and all three end in
    | `$course->delete()`, so a hook here guards all three (and whatever door is
    | added next) without anyone having to remember an Action. The panel also asks
    | {@see self::deletionRefusal()} in a `before()` so the sentence arrives as a
    | notification instead of an error page — presentation, not a second guard.
    |
    | ⚠️ `DeleteBulkAction::fetchSelectedRecords(false)` routes AROUND this hook
    | (one query `delete()`, which boots no model). Never set it on a course table.
    |
    | And soft deletes (the column has existed since the table did): even a course
    | nobody bought keeps its row, so nothing hanging off it by id is orphaned.
    */
    protected static function booted(): void
    {
        static::deleting(function (Course $course): void {
            $refusal = $course->deletionRefusal();

            if ($refusal !== null) {
                throw new CourseDeletionRefused($refusal);
            }
        });
    }

    /**
     * Why this course may not be deleted, or null when it may.
     *
     * ⚠️ RAW TABLES, NOT MODELS. `Enrollment` and `Order` are workspace-scoped,
     * and a platform officer deleting from `/admin` resolves a context from their
     * own `users.last_workspace_id` — the scope would AND that workspace on, count
     * zero rows about another teacher's course, and wave the deletion through.
     * Every enrolment counts whatever its status: a cancelled or expired one is
     * still somebody's record of what they bought.
     */
    public function deletionRefusal(): ?string
    {
        $id = $this->getKey();

        if (DB::table('enrollments')->where('course_id', $id)->exists()) {
            return 'لا يمكن حذف هذا الكورس لأنّ طلاباً سُجِّلوا فيه، وحذفُه يُفقدُهم ما اشتروه.';
        }

        // A credit balance is value a student holds against THIS course, and
        // `CreditBalanceResource` reads its course unguarded — asked on its own
        // rather than assumed to travel with an order.
        if (DB::table('orders')->where('course_id', $id)->exists()
            || DB::table('credit_balances')->where('course_id', $id)->exists()) {
            return 'لا يمكن حذف هذا الكورس لأنّ عليه طلباتِ شراءٍ أو أرصدةً للطلاب.';
        }

        return null;
    }

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_GROUP = 'group';

    public const TYPE_RECORDED = 'recorded';

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::typeLabels());
    }

    /**
     * ⚠️ **مفتاحٌ لكلِّ قيمة، لا مصفوفتانِ متوازيتان.** كانت لوحةُ Filament
     * تُركِّبُها بـ`array_combine(Course::types(), [...])` — فترتيبٌ يتغيّرُ
     * يُبدِّلُ التسمياتِ في صمت، وقيمةٌ رابعةٌ تُضاف ترمي `ValueError` تُسقِطُ
     * استمارةَ الكورسِ كلَّها. و`CourseVisibility::options()` بجوارِها في الملفِّ
     * نفسِه هي الإملاءُ الصحيح.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_INDIVIDUAL => 'فردي — حصص خاصّة',
            self::TYPE_GROUP => 'جماعي — مجموعات بمواعيد',
            self::TYPE_RECORDED => 'مسجّل — دروس بلا حصص حيّة',
        ];
    }

    protected $fillable = [
        'workspace_id',
        'title',
        'slug',
        'description',
        /*
        | `price` and `currency` are FROZEN, not extended.
        |
        | They price a one-off course order (`orders.kind = course`) and nothing
        | else: CreateOrder reads them, and Course::isFree() compares price to
        | zero to decide free enrolment — so removing them breaks two shipped
        | paths. Spec 006 writes no code that reads them; credit pricing is
        | cost-plus and per session, and a course total is not derivable from a
        | session rate.
        |
        | Known and deliberate: the teacher still sets this value, which FR-021ب
        | forbids for credit pricing. It is a grandfathered exception scoped to
        | course orders, not a precedent. Retiring it is a product decision with
        | revenue consequences and belongs to spec 011.
        */
        'price_minor',
        'currency',
        // The three pricing keys (spec 006, Q-7). teacher_profile_id is the one
        // without which the approved-rate lookup cannot run at all: RateResolver
        // starts from it, and `created_by` is nullable because a course may
        // outlive its author.
        'subject_id',
        'grade_level',
        /*
        | How long a private session in this course lasts (023 · FR-016أ). NULL
        | means the platform default — never «no private sessions».
        |
        | ⚠️ FILLABLE IN THE SAME CHANGE AS ITS MIGRATION. A column mass
        | assignment does not know about is discarded with no exception and no
        | log, and the response echoes what was SENT — so every test written
        | against the body passes over a row holding null.
        */
        'private_session_minutes',
        'teacher_profile_id',
        'status',
        'visibility',
        'is_sequential',
        'language',
        'duration_seconds',
        // Raised by every structural write, and sent back by the client on the
        // next one — an editor whose token is stale is looking at a tree that
        // has changed under them.
        'structure_version',
        'created_by',
        'course_type',
        'cover_path',
        'price_before_discount_minor',
        /*
        | The promotional video's ID on the teacher's own channel (018 · FR-004).
        |
        | ⚠️ THE EXTRACTED ID, NEVER THE PASTED URL. `PromoVideoUrl::extract()`
        | is the one spelling, and storing only the id is what makes FR-008
        | unrepresentable rather than merely checked.
        |
        | ⚠️ AND FILLABLE IN THE SAME CHANGE AS ITS MIGRATION. A column mass
        | assignment does not know about is discarded with no exception and no
        | log, and the response echoes what was SENT — so every test written
        | against the body passes over a row holding null (013's three columns).
        |
        | Its three siblings — status, reviewed_at, reviewed_by — are
        | deliberately NOT here: they are written by ReviewCoursePromoVideo and
        | SetCoursePromoVideo alone. Mass-assignable, the status becomes a second
        | door to the approval decision from outside the action that owns it
        | (the `captured_order_id` rule).
        */
        'promo_video_id',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'price_before_discount_minor' => 'integer',
            'is_sequential' => 'boolean',
            'duration_seconds' => 'integer',
            'private_session_minutes' => 'integer',
            'structure_version' => 'integer',
            // Stamped by Payments' StampCourseDelivery, never by course
            // authoring — and deliberately not fillable: the only writer is that
            // listener's conditional UPDATE (spec 006, FR-021ط).
            'last_delivered_at' => 'datetime',
            'promo_video_reviewed_at' => 'datetime',
        ];
    }

    public const PROMO_NONE = 'none';

    public const PROMO_PENDING = 'pending';

    public const PROMO_APPROVED = 'approved';

    public const PROMO_REJECTED = 'rejected';

    /**
     * Whether the promotional video may be shown to the public (018 · FR-006).
     *
     * ⚠️ THE ONE SPELLING OF THIS QUESTION. The public resource and the
     * teacher's own screen both read it, and two spellings of one question put
     * one answer on the screen and another at the door — the defect this
     * repository has paid for repeatedly.
     *
     * Both conditions, never one: the status alone could outlive a cleared id
     * through some later path, and the id alone is exactly what the review
     * exists to withhold.
     */
    public function hasApprovedPromoVideo(): bool
    {
        return $this->promo_video_status === self::PROMO_APPROVED
            && $this->promo_video_id !== null;
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('order');
    }

    /** @return HasMany<Chapter, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order');
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    /**
     * The platform-wide subject this course teaches.
     *
     * ⚠️ REFERENCE DATA, NOT A TENANT ROW. `subjects` is deliberately
     * platform-level — one «الرياضيات» for every teacher — so this relation
     * carries no workspace condition and needs none.
     *
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * هل لهذا الكورسِ حصّةٌ حيّةٌ واحدةٌ أصلاً؟
     *
     * ⛔ **الصيغةُ المُحمَّلةُ أوّلاً والاستعلامُ آخرَ فرع — إملاءُ
     * `ClassSessionResource::recordingLesson` بعينِه.** كانَ الـResource يقرأُ
     * `getAttribute('class_sessions_exists')` مباشرةً، فسمةٌ لم تُحمَّلْ تُقرَأُ
     * `false` **في صمت**: أربعةٌ من ستّةِ مواضعَ في `CourseController` تُرجِعُ
     * الصنفَ بلا `loadExists` (الإنشاءُ والتعديلُ ومراجعةُ الفيديو والنشر)،
     * فكانت جميعُها تقولُ «لا حصصَ لهذا الكورس» مهما كانَ في جدولِه.
     *
     * لا قارئَ يتضرّرُ اليوم — شاشةُ التعديلِ تُهمِلُ جسمَ الـPUT — لكنّ
     * `types.ts` يُعلِنُ `has_sessions: boolean` بلا شرط، فأوّلُ من يربطُ جوابَ
     * تعديلٍ بحالةِ الشاشةِ يأخذُ `false` واثقاً تُصادِقُ عليه TypeScript.
     * والفرعُ الأخيرُ استعلامٌ حقيقيٌّ لأنّ ناسياً يجبُ أن يدفعَ استعلاماً لا أن
     * ينشرَ جواباً خاطئاً.
     */
    public function hasClassSessions(): bool
    {
        $loaded = $this->getAttribute('class_sessions_exists');

        return $loaded !== null ? (bool) $loaded : $this->classSessions()->exists();
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * ⛔ **موجودةٌ لسؤالٍ واحد: «هل لهذا الكورسِ حصصٌ حيّةٌ أصلاً؟» — ولا تُقرَأُ
     * صفوفُها أبداً.**
     *
     * صفحةُ الكورسِ عندَ الطالبِ كانت تسألُ `course_type !== 'recorded'`، وذلكَ
     * العمودُ عاشَ بلا كاتبٍ من ٢٠٢٦-٠٨-٠١ إلى ٢٠٢٦-٠٩-١٥ — فكورسٌ على الإنتاجِ
     * بثماني حصصٍ حيّةٍ ومجموعةٍ مفتوحةٍ لم يعرضْ منها شيئاً لأربعةِ طلبةٍ
     * مسجَّلين. صارَ للعمودِ كاتبٌ، لكنّ **إعلانَ المدرّسِ ليسَ هو الواقع**:
     * اختيارٌ خاطئٌ منه يُخفي الجدولَ عن طلبتِه في صمتٍ ولا يظهرُ أثرُه لأحد،
     * بينما شارةً خاطئةً في السوقِ يراها الناسُ فيُبلِّغون. فالتبويبُ يمشي وراءَ
     * الواقعِ والشارةُ وراءَ الإعلان.
     *
     * ⚠️ **و`withExists`/`loadExists` وحدَهما، لا `with`**: الجوابُ بُولِيّ،
     * وتحميلُ فصلٍ دراسيٍّ كاملٍ من الحصصِ لتُعَدَّ لا شيءَ هو الـN+1 نفسُه بوجهٍ
     * آخر. والسمةُ الناتجةُ `class_sessions_exists`.
     *
     * ⚠️ **وحدودُ الوحداتِ مقصودة**: `Courses` تعرفُ `ClassSession` هنا كما
     * تعرفُها {@see LessonRelease} منذُ ٠٢٦ — ولا حارسَ عزلٍ بينَ الوحدتَينِ في
     * هذا المستودع، بخلافِ التسويةِ والمدفوعات.
     *
     * @return HasMany<ClassSession, $this>
     */
    public function classSessions(): HasMany
    {
        /*
        | ⛔ **النطاقُ يسقطُ داخلَ `exists`، وبدونِ هذا السطرِ يعودُ العطلُ الذي
        | كُتِبَت هذه العلاقةُ لإزالتِه — من بابٍ جديد.**
        |
        | `ClassSession` يحملُ `BelongsToWorkspace`، و`withExists`/`loadExists`
        | يبنيانِ الاستعلامَ الفرعيَّ من `newQuery()` **بنطاقاتِه**. فيصيرُ
        | الشرطُ `class_sessions.workspace_id = <مساحةُ القارئ>` لا مساحةَ
        | الكورس — و`WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id`،
        | المختومِ على كلِّ طالبٍ أضافَه مدرّسٌ أو دعوةٌ أو بذرةٌ إلى مساحة.
        |
        | قِيسَ: طالبٌ مختومٌ بمساحةِ المدرّسِ «ب» ومسجَّلٌ عندَ «أ» يقرأُ
        | `has_sessions = false` على كورسٍ له حصصٌ حيّة — فيختفي تبويبُ «الحصص»
        | وعدّادُ الحصّةِ القادمةِ من جديد. وهي قاعدةُ هذا المستودعِ بحرفِها:
        | «التجاوزُ لكلِّ نموذجٍ على حدة — `->with('order')` يُشغِّلُ نطاقَ Order
        | داخلَ استعلامِ العلاقة».
        |
        | ⚠️ **وحذفُ الشرطِ لا استبدالُه بمقارنةِ عمود.** كُتِبَت أوّلَ مرّةٍ
        | `whereColumn('class_sessions.workspace_id', 'courses.workspace_id')`
        | لتُبقِيَ فهرساً يخدمُ الاستعلام — وهي صحيحةٌ **داخلَ استعلامٍ فرعيٍّ
        | فقط**، حيثُ يكونُ `courses` في النطاق. أمّا العلاقةُ مسؤولةً عن نفسِها
        | (`$course->classSessions()->exists()`، وهو فرعُ الاحتياطِ في
        | {@see self::hasClassSessions()}) فلا `courses` هناك: قِيسَ **١٦ فشلاً**
        | بـ«no such column: courses.workspace_id». علاقةٌ لا تصلحُ إلّا في
        | موضعٍ واحدٍ هي فخٌّ لمن يستعملُها في الثاني.
        |
        | وثمنُ ذلكَ فهرسٌ: لا فهرسَ على `class_sessions` يبدأُ بـ`course_id`،
        | فالاستعلامُ الفرعيُّ بلا شرطِ المساحةِ كانَ مسحاً كاملاً. لذلكَ هجرةُ
        | `2026_09_16_000100_add_course_id_index_to_class_sessions` بجانبِه.
        */
        return $this->hasMany(ClassSession::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * A course is publicly listed when it is published, explicitly public, and
     * its author has a public page.
     *
     * `visibility` is checked separately from isPublished() on purpose: a course
     * can be published to an academy's own students without being offered to the
     * whole marketplace, and conflating the two would publish the first kind.
     *
     * The author condition is the third, and it is not cosmetic. There is no
     * standalone course page: the card's title links to the AUTHOR's profile,
     * because that is where the course can actually be booked. So a course whose
     * author has no public page is an entry in the marketplace whose only
     * destination is a 404 — the visitor's first interaction with it is the
     * error. Dropping the byline and listing it anyway leaves a card that cannot
     * be clicked at all, which is a different way of publishing nothing.
     *
     * `isPubliclyListed()` below answers the same question row by row for the
     * search index. The two must move together.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function publicListingConstraints(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('teacher_profiles')
                    ->whereColumn('teacher_profiles.user_id', 'courses.created_by')
                    ->where('teacher_profiles.is_publicly_listed', true)
                    // The constant rather than 'approved': a literal here is
                    // coupling to another module that nobody can grep for, and
                    // the two would drift the first time the value changed.
                    ->where('teacher_profiles.approval_status', TeacherProfile::STATUS_APPROVED);
            });
    }

    public function isFree(): bool
    {
        return $this->price_minor === 0;
    }

    public function searchableAs(): string
    {
        return 'courses_index';
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'price_minor' => $this->price_minor,
            // Indexed, not filtered after the fact (R2): Scout runs outside every
            // global scope, so an unpublished course excluded only on the way out
            // would still consume a result slot and leak its title in the count.
            'is_publicly_listed' => $this->isPubliclyListed(),
        ];
    }

    /**
     * Row-level answer to the same question scopePubliclyListed() asks in SQL.
     *
     * Used by the search index, which has no query to attach a scope to. It has
     * to stay in step with `publicListingConstraints()` above — including the
     * author condition, or search would surface exactly the courses the listing
     * refuses to show.
     */
    public function isPubliclyListed(): bool
    {
        $profile = $this->creator?->teacherProfile;

        return $this->isPublished()
            && $this->visibility === 'public'
            && (bool) $this->workspace?->participates_in_marketplace
            && $profile !== null
            && (bool) $profile->is_publicly_listed
            && $profile->approval_status === TeacherProfile::STATUS_APPROVED;
    }
}
