<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Replace a teacher's whole weekly availability.
 *
 * Full replacement, not a merge: a teacher editing their week expects the result
 * to be what they submitted, and merging leaves deleted windows bookable.
 *
 * The overlap rule is enforced here, not only in validation, because the wizard,
 * the availability endpoint and Filament all arrive through this Action
 * (Constitution II) and two of them have no FormRequest.
 */
class SetAvailability extends Action
{
    /** @param list<array{day_of_week: int, start_time: string, end_time: string}> $slots */
    public function handle(TeacherProfile $teacher, array $slots): void
    {
        $this->assertNoOverlaps($slots);

        DB::transaction(function () use ($teacher, $slots): void {
            $teacher->availabilitySlots()->delete();

            foreach ($slots as $slot) {
                AvailabilitySlot::query()->create([
                    'workspace_id' => $teacher->workspace_id,
                    'teacher_profile_id' => $teacher->getKey(),
                    ...$slot,
                ]);
            }
        });

        // "متاح الآن" is a filter on these rows, so a stale list would advertise a
        // window the teacher just deleted (SC-010).
        MarketplaceCache::flush();
    }

    /** @param list<array{day_of_week: int, start_time: string, end_time: string}> $slots */
    private function assertNoOverlaps(array $slots): void
    {
        foreach ($slots as $slot) {
            if ($slot['end_time'] <= $slot['start_time']) {
                throw new DomainException('وقت النهاية يجب أن يكون بعد وقت البداية.');
            }
        }

        $byDay = [];

        foreach ($slots as $slot) {
            $byDay[$slot['day_of_week']][] = $slot;
        }

        foreach ($byDay as $daySlots) {
            usort($daySlots, fn (array $a, array $b) => $a['start_time'] <=> $b['start_time']);

            for ($i = 1; $i < count($daySlots); $i++) {
                // Touching windows (10:00–12:00 then 12:00–14:00) are fine; the
                // comparison is strict so only a genuine overlap is rejected.
                if ($daySlots[$i]['start_time'] < $daySlots[$i - 1]['end_time']) {
                    throw new DomainException('لا يمكن أن تتداخل فترتان في اليوم نفسه.');
                }
            }
        }
    }
}
