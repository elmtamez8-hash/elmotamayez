<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Whiteboard\Enums\BoardBackground;
use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Whiteboard\BoardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A teacher's whiteboard (spec 039): an ordered set of 16:9 pages.
 *
 * Workspace-owned. «The board's owning teacher» is DERIVED, never stored (owner
 * decision D1): the course's teacher when the board has a course, else the creator
 * (`owner_user_id`) — see `BoardOwnership`.
 *
 * ⚠️ NOT FILLABLE: `pages_count`, `pending_operation` and every `editor_*` column.
 * Each moves only by the conditional UPDATE that owns it (`BoardPageGate`,
 * `BoardLock`, the queued jobs) — mass-assignable, each would gain a second way to
 * change outside the statement whose WHERE clause is the guard.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $owner_user_id
 * @property string $title
 * @property int|null $course_id
 * @property int|null $lesson_id
 * @property int|null $class_session_id
 * @property BoardBackground $background
 * @property int|null $editor_user_id
 * @property string|null $editor_tab_id
 * @property string|null $editor_seen_at
 * @property string|null $editor_handover_tab
 * @property string|null $editor_handover_at
 * @property int $pages_count
 * @property BoardPendingOperation|null $pending_operation
 */
class Board extends BaseModel
{
    /** @use HasFactory<BoardFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'owner_user_id',
        'title',
        'course_id',
        'lesson_id',
        'class_session_id',
        'background',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'background' => BoardBackground::class,
            'pending_operation' => BoardPendingOperation::class,
            'pages_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Read WITH trashed rows: courses are soft-deleted, so `nullOnDelete` never
     * fires and a board keeps pointing at a deleted course — whose name it still shows.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<ClassSession, $this> */
    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_user_id');
    }

    /** @return HasMany<BoardPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(BoardPage::class)->orderBy('position');
    }

    /** @return HasMany<BoardLessonExport, $this> */
    public function lessonExports(): HasMany
    {
        return $this->hasMany(BoardLessonExport::class);
    }
}
