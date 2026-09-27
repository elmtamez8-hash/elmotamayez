<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;

/**
 * The dormant-credit notice stops pointing at a screen that does not exist.
 *
 * The shipped body ended «أو طلب استرداده» — as if a refund were a button the
 * student could press. There is none: every refund is an officer's decision in
 * `/admin`, reached by talking to support (`/refunds` says so). A student who
 * went looking for the button found nothing, and the one door that does work
 * went unnamed. The new sentence names it (owner decision 2026-09-27).
 *
 * The update is CONDITIONAL on the body still being the shipped text, word for
 * word, on the precedent of
 * `2026_09_25_000100_reword_certificate_regenerated_without_revoking`: every
 * template is editable from `/admin`, and replacing an operator's own wording
 * without asking is worse than a sentence they can see and fix.
 *
 * ⚠️ Through the model, never `DB::table()`: `body` is a translatable JSON
 * document since 055, and a raw write stores a bare string that reads as empty.
 * Every channel's row, because the WhatsApp row carries the same body as
 * documentation — the wording approved at the provider is a separate, human
 * step. The variable order (`course`, `months`, `credits`) is unchanged.
 */
return new class extends Migration
{
    public const SHIPPED_BODY = 'لم تستخدم رصيدك في «{{ course }}» منذ {{ months }} شهراً، وما زالت لديك {{ credits }} حصة. الرصيد لا ينتهي، ويمكنك استخدامه في أي وقت أو طلب استرداده.';

    public const REWORDED_BODY = 'لم تستخدم رصيدك في «{{ course }}» منذ {{ months }} شهراً، وما زالت لديك {{ credits }} حصة. الرصيد لا ينتهي، ويمكنك استخدامه في أي وقت، أو تواصل مع الدعم لطلب استرداد رصيدك.';

    public function up(): void
    {
        MessageTemplate::query()
            ->where('type', NotificationType::CreditBalanceDormant->value)
            ->get()
            ->each(function (MessageTemplate $template): void {
                if ($template->body !== self::SHIPPED_BODY) {
                    return;
                }

                $template->forceFill(['body' => self::REWORDED_BODY])->save();
            });
    }

    /** Empty on purpose — restoring a pointer to a button that does not exist is not a rollback anyone wants. */
    public function down(): void {}
};
