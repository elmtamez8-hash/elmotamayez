<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\ClassSessionFeedback;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Collection;

/**
 * The teacher's short remark on each student in one session (FR-036).
 *
 * Written against the seats, not against the register: the register only fills
 * up as the session runs and is not final until it closes, so validating
 * against it would refuse a remark on a student who has not pinged yet — which
 * is most of them, most of the time. The seat is the fact that exists from the
 * moment the session concerns that student at all.
 *
 * Nothing here blocks the report. FR-035 forbids waiting for a remark that may
 * never come, so this Action and SendSessionReportsJob touch no shared state
 * beyond the row itself — a remark saved after the report has gone produces a
 * correction through the same path an attendance edit does.
 */
class SubmitSessionFeedback extends Action
{
    /**
     * @param  array<int, array{student_uuid: string, rating?: int|null, note?: string|null}>  $entries
     * @return Collection<int, ClassSessionFeedback>
     */
    public function handle(ClassSession $session, User $author, array $entries): Collection
    {
        /** @var array<string, int> $studentIdsByUuid */
        $studentIdsByUuid = [];

        $seats = $session->bookings()
            ->whereIn('status', [BookingStatus::Booked, BookingStatus::CancelledLate])
            ->with('student')
            ->get();

        foreach ($seats as $seat) {
            $student = $seat->student;

            if ($student !== null) {
                $studentIdsByUuid[(string) $student->uuid] = (int) $seat->student_user_id;
            }
        }

        /** @var Collection<int, ClassSessionFeedback> $saved */
        $saved = collect();

        foreach ($entries as $entry) {
            $uuid = (string) $entry['student_uuid'];

            if (! isset($studentIdsByUuid[$uuid])) {
                throw new DomainException('هذا الطالب لا يملك مقعداً في هذه الحصة.');
            }

            $feedback = ClassSessionFeedback::query()->updateOrCreate(
                [
                    'class_session_id' => $session->getKey(),
                    'student_user_id' => $studentIdsByUuid[$uuid],
                ],
                [
                    'workspace_id' => $session->workspace_id,
                    'rating' => $entry['rating'] ?? null,
                    'note' => $entry['note'] ?? null,
                    'created_by' => $author->getKey(),
                ],
            );

            $saved->push($feedback);
        }

        return $saved;
    }
}
