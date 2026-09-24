<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;

/**
 * `enrollment_created` speaks in the third person now that a guardian reads it.
 *
 * One record per type serves every recipient (003 · FR-007), so the shipped
 * sentence ("hello {{ name }}, YOU were enrolled") reached a parent addressed as
 * the child. The new one names the child and reads true to both of them.
 *
 * CONDITIONAL on the row still carrying the shipped text word for word, on the
 * precedent of `2026_09_23_000600_reword_receipt_approved_for_every_kind`: an
 * operator's own wording is theirs to change. Title and body are judged
 * separately for the same reason. Through the model, never `DB::table()` — both
 * are translatable JSON documents since 055, and a raw write reads back empty.
 *
 * The WhatsApp row does not exist yet on an existing database; the backfill
 * after this file writes it from the seeder, already in the new wording.
 */
return new class extends Migration
{
    private const SHIPPED_TITLE = 'تم تسجيلك في كورس';

    private const SHIPPED_BODY = 'مرحباً {{ name }}، تم تسجيلك في «{{ course_title }}». يمكنك البدء الآن.';

    private const REWORDED_TITLE = 'تسجيل جديد في كورس';

    private const REWORDED_BODY = 'تم تسجيل {{ name }} في «{{ course_title }}»، ويمكن البدء الآن.';

    public function up(): void
    {
        MessageTemplate::query()
            ->where('type', NotificationType::EnrollmentCreated->value)
            ->get()
            ->each(function (MessageTemplate $template): void {
                $changes = [];

                if ($template->title === self::SHIPPED_TITLE) {
                    $changes['title'] = self::REWORDED_TITLE;
                }

                if ($template->body === self::SHIPPED_BODY) {
                    $changes['body'] = self::REWORDED_BODY;
                }

                if ($changes !== []) {
                    $template->forceFill($changes)->save();
                }
            });
    }

    /** Empty on purpose — see its predecessors. */
    public function down(): void {}
};
