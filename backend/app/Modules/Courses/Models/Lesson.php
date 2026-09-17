<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Support\HasSiblingOrder;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\OrdersSiblings;
use App\Modules\Courses\Support\ReferenceIntegrity;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Courses\LessonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;

/**
 * @property string $type
 * @property ContentStatus $status
 * @property int|null $reference_id
 * @property ExamGate|null $exam_gate
 * @property string|null $external_url
 * @property int|null $class_session_id
 * @property int|null $release_session_id
 */
class Lesson extends BaseModel implements OrdersSiblings
{
    /** @use HasFactory<LessonFactory> */
    use BelongsToWorkspace, HasFactory, HasSiblingOrder, HasUuid;

    public function siblingScopeColumn(): string
    {
        return 'chapter_id';
    }

    protected $fillable = [
        'workspace_id',
        'course_id',
        'section_id',
        'chapter_id',
        // Set when this lesson was published from a live session's recording.
        // Its presence changes who may watch: entitlement comes from a seat in
        // that session, not from enrolment in the course (FR-030).
        'class_session_id',
        'title',
        'type',
        'status',
        'content',
        'external_url',
        // The exam or session this item places. Interpreted by `type` — there is
        // deliberately no second column saying which, because two columns
        // recording one fact can disagree.
        'reference_id',
        // What the referenced exam asks before the course goes on. Null on every
        // other type — a gate on an article is a fact with no meaning (FR-041).
        'exam_gate',
        'order',
        'duration_seconds',
        'is_preview',
        'is_free',
        'is_high_value',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'exam_gate' => ExamGate::class,
            'order' => 'integer',
            'duration_seconds' => 'integer',
            'is_preview' => 'boolean',
            'is_free' => 'boolean',
            'is_high_value' => 'boolean',
        ];
    }

    /**
     * Items a student may see: published, inside a published chapter, inside a
     * published section.
     *
     * The chain matters (FR-028). A published lesson in a draft section is
     * hidden, and the teacher is shown that reason rather than the lesson's own
     * state — "published but blocked by its section" is a different problem to
     * solve than "still a draft".
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeVisibleToStudents(Builder $query): Builder
    {
        // An item whose exam or session has been deleted points at nothing, and
        // nobody can sit an exam that is gone (FR-045).
        ReferenceIntegrity::apply($query);

        /*
        | ⚠️ THE PARENTS' SCOPE IS BYPASSED INSIDE THE SUBQUERIES, AND «the caller
        | already called `withoutWorkspaceScope()`» IS NOT ENOUGH — the bypass is
        | PER MODEL, and `whereHas` runs Chapter's and Section's OWN global scopes
        | inside their own subqueries.
        |
        | Measured 032 · T023: the public course page returned an EMPTY curriculum
        | to a teacher signed in from another workspace, whose context resolves
        | from `users.last_workspace_id` — while a guest read it perfectly,
        | because `WorkspaceScope` adds no condition when the context is null. The
        | family of `->with('order')` answering null inside a platform report.
        |
        | Nothing is widened: both subqueries are already tied to THIS lesson's
        | row by foreign key, so they can match no other workspace's chapter.
        |
        | ⚠️ SPELLED THE LONG WAY HERE, AND ONLY HERE. `withoutWorkspaceScope()`
        | is a model SCOPE, and a `whereHas` closure is typed `Builder<Model>` —
        | so the scope is invisible to the analyser and level 8 refuses the call.
        | The trait's whole body is `withoutGlobalScope(WorkspaceScope::class)`,
        | which is this line: the same operation, in the spelling the type system
        | can check, rather than an ignore comment over a call it cannot see.
        */
        return $query
            ->where('lessons.status', ContentStatus::Published)
            ->whereHas('chapter', fn (Builder $q) => $q->withoutGlobalScope(WorkspaceScope::class)->where('status', ContentStatus::Published))
            ->whereHas('section', fn (Builder $q) => $q->withoutGlobalScope(WorkspaceScope::class)->where('status', ContentStatus::Published));
    }

    /**
     * The same three conditions as `visibleToStudents`, asked of THIS row.
     *
     * A scope answers "which rows", and two readers need "does this one" —
     * `Enrollment::accessTo` and `IssuePlaybackGrant::mayWatch`, the two places
     * that decide what one student may open. Both had no status check at all, so a
     * draft item's body and its video were served to anyone enrolled; asking the
     * scope again per row would be a second query for a fact already loaded.
     *
     * Reads the parents through `loadMissing`, so a caller that already eager
     * loaded them — both do, for the ordering — pays nothing.
     */
    public function isVisibleChain(): bool
    {
        // The same per-model bypass as the scope above, for the same measured
        // reason: a reader whose context resolves elsewhere would load `null` for
        // both parents and be told a published lesson is hidden.
        $this->loadMissing([
            'section' => fn ($query) => $query->withoutWorkspaceScope(),
            'chapter' => fn ($query) => $query->withoutWorkspaceScope(),
        ]);

        return $this->status->isVisibleToStudents()
            && $this->chapter?->status->isVisibleToStudents() === true
            && $this->section?->status->isVisibleToStudents() === true;
    }

    /**
     * «Open» — the lesson its owner decided anyone may have (032 · FR-008).
     *
     * ⚠️ ONE SPELLING NOW, AND THIS METHOD IS IT. Every door that asks «is this
     * item open» calls here: `IssuePlaybackGrant` at :241 and :360, and
     * `LessonGate` at :103 and :519.
     *
     * ⛔ THIS PARAGRAPH USED TO SAY THE OPPOSITE, AND IT COST A SESSION. It read
     * «`LessonGate` asks `is_preview` ALONE at :83 and :383 … the absurd
     * consequence is live today», describing a divergence the owner closed on
     * 2026-09-09 — `LessonGate:86` carries the decision in its own words. The
     * comment was never moved with the code, so a reader measuring from it
     * concluded that a public «this lesson is free» badge would send a student
     * to a door that refuses, and nearly narrowed a correct predicate to fix a
     * defect that no longer existed. Read the assignment, never the paragraph
     * above it — the same rule this tree wrote down for `AccessToken::$ttl`,
     * where a vendor docblock said six hours over a line that assigns four.
     *
     * A docblock that cites line numbers is a measurement, and a measurement is
     * re-taken when the code around it moves.
     */
    public function isOpen(): bool
    {
        return $this->is_preview || $this->is_free;
    }

    /**
     * Whether a visitor with no account may read THIS lesson (032 · FR-019).
     *
     * ⛔ THIS, NEVER {@see isOpen}, IS WHAT THE PUBLIC SIDE ASKS. Every place
     * that says «this lesson is open to a visitor» reads this one — the public
     * curriculum tree, the public lesson query, and the `is_open` flag in the
     * payload. A tree that advertises with the first while the door measures
     * with the second publishes PERMANENTLY DEAD LINKS: an open uploaded video
     * renders clickable and answers the 404 that means «no such thing», with
     * neither the visitor nor the teacher given a reason.
     *
     * The `embed` condition is not cosmetic narrowing. `PublicFieldAllowlist`
     * says in as many words that a lesson uuid in a public payload is an
     * invitation to try it against the playback endpoint — and it is right:
     * `IssuePlaybackGrant::mayWatch()` answers yes to ANY signed-in account for
     * ANY open lesson, across every workspace. An embed has no media asset, so
     * its uuid opens no bytes there.
     */
    public function isPubliclyReadable(): bool
    {
        return $this->isOpen()
            && $this->type === LessonType::Embed->value
            && $this->isVisibleChain();
    }

    /**
     * The items a course's completion percentage is measured against.
     *
     * Two halves. The status chain is asked here, because publishing changes it;
     * everything else is asked by `progressEligible` below, because publishing
     * cannot — which is what lets the impact preview simulate one and reuse the
     * other rather than growing a second denominator (`FR-049` · `SC-018`).
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeCountableForProgress(Builder $query): Builder
    {
        return $query
            ->progressEligible()
            ->where('lessons.status', ContentStatus::Published)
            // The chain, exactly as `visibleToStudents` requires it — and the
            // reason this line exists is that it did NOT. A lesson published
            // inside a draft chapter is the state `blockedBy` renders for the
            // teacher as `blocked_by: "chapter"`: hidden from the student by
            // `visibleToStudents`, ungrantable by `accessTo`, and yet counted in
            // the denominator. Nobody could ever complete it, so every enrolled
            // student's ceiling sat below 100% and no certificate issued. A
            // fourth road into the same forever-bug, through the publish chain.
            /*
            | ⛔ **والتجاوزُ داخلَ الاستعلامَينِ الفرعيَّين، وهو عطلٌ قائمٌ وجدَه
            | اختبارُ ٠٢٦ لا شيءٌ أحدثَته المواصفة.**
            |
            | `scopeVisibleToStudents` أعلاه يحملُ هذا التجاوزَ بعينِه ومعَه
            | تحذيرٌ يشرحُه، وهذه الدالّةُ — على بُعدِ عشرةِ أسطر — لم تحملْه.
            | والتجاوزُ **لكلِّ نموذجٍ على حدة**: `whereHas` تُجري نطاقَ `Chapter`
            | و`Section` داخلَ استعلامَيهما، فقارئٌ سياقُه مساحةٌ أخرى يحصلُ على
            | **صفرٍ** من هذه الدالّة.
            |
            | وصفرٌ هنا ليسَ رقماً ناقصاً: `progress_pct` صفرٌ إلى الأبد،
            | و`$total > 0` في `CourseProgress::sync()` تمنعُ إتمامَ الكورسِ فلا
            | يقعُ `CourseCompleted` ولا تصدرُ شهادةٌ أبداً — عائلةُ أسوأِ عطلٍ
            | يسجّلُه هذا المستودع، تصلُ من بابِ النطاقِ هذه المرّة. ويُصيبُ كلَّ
            | طالبٍ مطبوعٍ عليه `last_workspace_id`، وهم كلُّ من أُضيفَ يوماً إلى
            | مساحةِ عمل.
            |
            | ⚠️ ولا يُوسَّعُ شيء: الاستعلامانِ مربوطانِ بمفتاحِ هذا الصفِّ
            | الأجنبيِّ، فلا يطالانِ فصلاً ولا قسماً من مساحةٍ أخرى.
            */
            ->whereHas('chapter', fn (Builder $q) => $q->withoutGlobalScope(WorkspaceScope::class)->where('status', ContentStatus::Published))
            ->whereHas('section', fn (Builder $q) => $q->withoutGlobalScope(WorkspaceScope::class)->where('status', ContentStatus::Published));
    }

    /**
     * صفُّ الشجرةِ الذي يُشيرُ إلى هذا المرجع — ورقةٌ أو حصّة.
     *
     * ⚠️ **عمودانِ معاً لا `reference_id` وحدَه.** المعرّفاتُ مستقلّةٌ لكلِّ
     * جدول، فرقم ٧ ورقةٌ ورقم ٧ حصّةٌ في آنٍ واحد — وشرطٌ بلا `type` يُرجِعُ
     * صفَّ حصّةٍ حكماً على ورقة.
     *
     * ⚠️ **وموضعُه هنا لأنّ له قارئَين**: `StartAttempt` تسألُه لتقرأَ الحصّةَ
     * والحكمَ، و`ExamController::show()` تسألُه للحكمِ وحدَه. تهجئتانِ لشرطٍ
     * واحدٍ تفترقانِ عندَ أوّلِ نوعٍ يُضاف.
     *
     * @param  Builder<Lesson>  $query
     */
    public function scopeReferencing(Builder $query, string $type, int $referenceId): void
    {
        $query->where('type', $type)->where('reference_id', $referenceId);
    }

    /**
     * The half of `countableForProgress` that a publish cannot change.
     *
     * Split out for the impact preview (`FR-049`), which has to answer what the
     * denominator WOULD be — so it must simulate the three status conditions and
     * must not simulate anything else. Every condition here is a fact about the
     * item itself that publishing leaves untouched:
     *
     * - a type nothing is asked of (`note`, `link`, an unheld `live_session`)
     *   can never be completed, so it never counts;
     * - a session recording is entitled by a SEAT, not by enrolment (005
     *   `FR-030`), so counting it caps every seatless student below 100% forever;
     * - an item whose exam was deleted points at nothing (`FR-045`), same road.
     *
     * Keeping them in a scope rather than restating them in the preview is the
     * whole point: a preview that drew its own version of "countable" would be a
     * second denominator, and `SC-018` is the promise that there is only one.
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeProgressEligible(Builder $query): Builder
    {
        ReferenceIntegrity::apply($query);

        /*
        | ⛔ ٠٢٦ · FR-013أ — **والشرطانِ خاصّيّتانِ في العنصرِ نفسِه، لا في
        | القارئ.** فالمقامُ يبقى **واحداً لكلِّ كورس** مهما اختلفَ مَن يقرؤُه،
        | وتصيرُ «لا تنقصُ نسبةُ أحدٍ بتضييقِ عنصر» صحيحةً بالبناءِ لا بالحراسة.
        |
        | ومقامٌ يختلفُ باختلافِ القارئِ ليسَ تحسيناً بل عطلٌ من نوعٍ آخر: طالبانِ
        | في الكورسِ نفسِه يريانِ رقمَينِ مختلفَينِ عن العملِ نفسِه، وشهادةٌ تصدرُ
        | لأحدِهما ولا تصدرُ للآخر.
        |
        | ⚠️ **و`whereNotIn` على استعلامٍ فرعيٍّ خامٍّ لا `whereDoesntHave`**:
        | العلاقةُ تجري تحتَ `WorkspaceScope`، و`WorkspaceContext::id()` يرجعُ إلى
        | `users.last_workspace_id` المطبوعِ على كلِّ طالبٍ أُضيفَ يوماً إلى
        | مساحةِ عمل — فتُرجِعُ العلاقةُ صفراً لذلكَ القارئ، ويدخلُ العنصرُ
        | المقصورُ مقامَه وحدَه. وهو مقامٌ لا يستطيعُ إتمامَه أبداً: أسوأُ عطلٍ
        | يسجّلُه هذا المستودع، ولا تجهيزةَ بمساحةِ عملٍ واحدةٍ تراه.
        |
        | ⚠️ **و«مربوطٌ بحصّة» لا «لم يُفرَجْ عنه بعد»** (FR-013 مقابلَ FR-013أ).
        | الثاني يُدخِلُ العنصرَ المقامَ يومَ تُعقَدُ الحصّة، فتنقصُ نسبةُ طالبٍ
        | كانَ على ١٠٠٪ — وهو ما تمنعُه FR-014 نصّاً.
        */
        return $query
            ->whereIn('lessons.type', LessonTypeRegistry::completableValues())
            ->whereNull('lessons.class_session_id')
            ->whereNull('lessons.release_session_id')
            ->whereNotIn('lessons.id', DB::table('lesson_cohort_scopes')->select('lesson_id'));
    }

    /**
     * The lesson's own file — its video, its audio, its document.
     *
     * Replaces the old `media` JSON column, which held a path on the public disk
     * — a permanent link that worked forever for anyone who copied it.
     *
     * **Scoped to `primary`, and that is not cosmetic.** Since 016 a lesson may
     * carry attachments beside its own file, and an unscoped `morphOne` returns
     * whichever row the database hands back first. Everything that reads this —
     * the resource's `asset` field, the replace-on-re-upload path, the duration
     * sync — would then be describing an arbitrary worksheet. It fails silently,
     * which is why the scope is here rather than at each call site.
     *
     * @return MorphOne<MediaAsset, $this>
     */
    public function mediaAsset(): MorphOne
    {
        return $this->morphOne(MediaAsset::class, 'owner')->where('role', MediaRole::Primary);
    }

    /**
     * Files attached beside the item, whatever its type (FR-019).
     *
     * A worksheet under a video, the slides under an article. Many per lesson,
     * where the primary is one — enforced in the Action, since MySQL has no
     * partial unique index to say "one row per owner where role = primary".
     *
     * @return MorphMany<MediaAsset, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'owner')->where('role', MediaRole::Attachment);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * المجموعاتُ التي قُصِرَ عليها هذا العنصر — و**لا صفَّ يعني للجميع**.
     *
     * ⚠️ **للكتابةِ وللوحةِ الإدارة، لا لمسارِ الطالب.** العلاقةُ تجري تحتَ
     * `WorkspaceScope` مثلَ أيِّ استعلامٍ على النموذج، و`LessonAudience` يقرأُ
     * الصفوفَ بـ`DB::table` لذلك — انظرْ دفترَ {@see LessonCohortScope}.
     *
     * ولا علاقةَ لـ`release_session_id` هنا: `ClassSession` نموذجُ وحدةٍ أخرى،
     * وعلاقةٌ عابرةٌ للوحداتِ هي الحدُّ الذي يسقطُ أوّلَ مرّة — والمعرّفُ وحدَه
     * كافٍ، والسؤالُ عن حالِ الحصّةِ يُطرَحُ على `SessionAttendanceDirectory`.
     *
     * @return HasMany<LessonCohortScope, $this>
     */
    public function cohortScopes(): HasMany
    {
        return $this->hasMany(LessonCohortScope::class);
    }
}
