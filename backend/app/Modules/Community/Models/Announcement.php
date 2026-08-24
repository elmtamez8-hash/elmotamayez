<?php

declare(strict_types=1);

namespace App\Modules\Community\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Community\AnnouncementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One notice from a teacher to a slice of their students (FR-042).
 *
 * @property int $author_user_id
 * @property string $scope
 * @property int|null $scope_id
 * @property string $body
 * @property bool $is_urgent
 * @property Carbon|null $published_at
 * @property Carbon|null $hidden_at
 * @property array{notified: int, read: int}|null $stats stamped for a list, never a column
 */
class Announcement extends BaseModel
{
    /** @use HasFactory<AnnouncementFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    /** The whole scope vocabulary. «Groups» are out of scope, declared (ت-٣). */
    public const SCOPE_ALL = 'all';

    public const SCOPE_COURSE = 'course';

    public const SCOPE_SESSION = 'session';

    /**
     * What `notifications.source_type` carries for a row this produced.
     *
     * ⚠️ A STABLE KEY, NEVER `self::class`. A class name is a refactoring away
     * from changing, and the day it does every announcement ever published loses
     * its two counters — silently, because the query still runs and answers zero.
     */
    public const SOURCE_TYPE = 'announcement';

    /**
     * ⚠️ `published_at` AND `hidden_at` ARE NOT FILLABLE. Both are claimed by a
     * conditional UPDATE — mass-assignable, they become a second way to publish
     * from outside the Action that owns the claim, and the fan-out then runs
     * twice or not at all. The `captured_order_id` rule.
     *
     * `scope_id` is not fillable either: it is an internal id, resolved from a
     * uuid inside the Action after the workspace check.
     */
    protected $fillable = [
        'workspace_id',
        'author_user_id',
        'scope',
        'body',
        'is_urgent',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'is_urgent' => 'boolean',
            'published_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isLive(): bool
    {
        return $this->published_at !== null && $this->hidden_at === null;
    }
}
