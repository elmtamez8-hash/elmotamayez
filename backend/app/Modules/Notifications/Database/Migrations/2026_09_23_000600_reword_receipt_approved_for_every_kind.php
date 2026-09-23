<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;

/**
 * «اعتُمد إيصالك» stops promising a balance to a buyer who has none.
 *
 * The shipped body ended «وأُضيف رصيدك» — true of a credit purchase and false
 * of a store purchase, and the listener that finally sends this type sends it
 * for both. The update is CONDITIONAL on the body still being the shipped text,
 * word for word, on the precedent of
 * `2026_09_18_000300_reword_plan_created_for_you_for_both_shapes`: replacing an
 * operator's own wording without asking is worse than a sentence they can see
 * and fix themselves.
 *
 * ⚠️ Through the model, never `DB::table()`: `body` is a translatable JSON
 * document since 055, and a raw write stores a bare string that reads as empty.
 * Both channels, because the WhatsApp row carries the same body as
 * documentation and nothing has ever been sent through it.
 */
return new class extends Migration
{
    private const SHIPPED_BODY = 'راجع الفريق إيصالك عن «{{ course }}» واعتمده، وأُضيف رصيدك.';

    private const REWORDED_BODY = 'راجع الفريق إيصالك عن «{{ course }}» واعتمده، وبدأ تنفيذ طلبك.';

    public function up(): void
    {
        MessageTemplate::query()
            ->where('type', NotificationType::ReceiptApproved->value)
            ->get()
            ->each(function (MessageTemplate $template): void {
                if ($template->body !== self::SHIPPED_BODY) {
                    return;
                }

                $template->forceFill(['body' => self::REWORDED_BODY])->save();
            });
    }

    /** Empty on purpose — see its predecessors. */
    public function down(): void {}
};
