<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Course;
use Database\Factories\Modules\Community\AssistantScopeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One course an assistant is confined to.
 *
 * ⚠️ NO `BelongsToWorkspace`, AND THEREFORE NO GLOBAL SCOPE AT ALL. The row
 * carries no tenant key by design: it is reachable only through its assignment,
 * which carries one. That makes the join the guard — a bare
 * `AssistantScope::query()` returns every workspace's rows, exactly like any
 * platform-owned table. Read it through the relation or through
 * `EloquentAssistantScopeDirectory`, never directly.
 *
 * ⚠️ AND NO `HasUuid` EITHER. Nothing addresses a scope row from outside: the
 * complete sibling list is replaced by one `PUT .../scope`, and the payload
 * names COURSES by their uuid. A route key here would be a handle on an object
 * the API does not expose.
 *
 * @property int $assistant_assignment_id
 * @property int $course_id
 */
class AssistantScope extends BaseModel
{
    /** @use HasFactory<AssistantScopeFactory> */
    use HasFactory;

    protected $fillable = [
        'assistant_assignment_id',
        'course_id',
    ];

    /** @return BelongsTo<AssistantAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssistantAssignment::class, 'assistant_assignment_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
