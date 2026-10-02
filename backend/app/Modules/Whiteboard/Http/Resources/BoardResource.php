<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Resources;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Modules\Whiteboard\Support\BoardOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A board in a list (contracts/api.md). `can` is what the screen reads — it never
 * derives a permission itself.
 *
 * Cheap per row only because the list primes it: `ListBoards` eager-loads the
 * people and `BoardOwnership::primeFor()` answers every course's teacher in two
 * queries, and `BoardPolicy` reads roles through the per-request `MemberRoles`.
 *
 * @mixin Board
 */
class BoardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Board $board */
        $board = $this->resource;
        /** @var User $reader */
        $reader = $request->user();
        $course = $board->course_id === null ? null : $board->course;
        $teacher = $course instanceof Course ? ($course->teacherUser() ?? $board->owner) : $board->owner;

        return [
            'uuid' => $board->uuid,
            'title' => $board->title,
            'background' => $board->background->value,
            'pages_count' => $board->pages_count,
            'course' => $course instanceof Course ? [
                'uuid' => $course->uuid,
                'title' => $course->title,
                'deleted' => $course->trashed(),
            ] : null,
            'lesson' => $board->lesson_id === null || $board->lesson === null ? null : [
                'uuid' => $board->lesson->uuid,
                'title' => $board->lesson->title,
            ],
            'owner' => self::person($board->owner),
            'teacher' => self::person($teacher),
            'updated_at' => $board->updated_at?->toIso8601String(),
            'lock' => ['held_by' => self::person($this->lockHolder($board))],
            'can' => [
                'edit' => Gate::forUser($reader)->allows('update', $board),
                'take_lock' => Gate::forUser($reader)->allows('takeLock', $board),
                'export' => Gate::forUser($reader)->allows('export', $board),
                'delete' => Gate::forUser($reader)->allows('delete', $board),
            ],
        ];
    }

    /** The editor while their heartbeat is fresh; a silent lock shows nobody. */
    private function lockHolder(Board $board): ?User
    {
        if ($board->editor_user_id === null || $board->editor_seen_at === null) {
            return null;
        }

        $expired = BoardLock::stamp(CarbonImmutable::now()->subSeconds(BoardLock::EXPIRES_AFTER_SECONDS));

        return (string) $board->editor_seen_at >= $expired ? $board->editor : null;
    }

    /** @return array{uuid: string, name: string}|null */
    private static function person(?User $user): ?array
    {
        return $user === null ? null : ['uuid' => (string) $user->uuid, 'name' => $user->name];
    }
}
