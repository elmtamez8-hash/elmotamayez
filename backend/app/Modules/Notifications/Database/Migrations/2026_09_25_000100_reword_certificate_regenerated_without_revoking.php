<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;

/**
 * «أُعيد إصدار شهادتك» stops telling the student their certificate was revoked.
 *
 * The shipped body ended «النسخة السابقة لم تعد سارية» — false on every count.
 * A certificate is rendered live from its row, so there is no earlier copy to
 * expire; `RegenerateCertificate` touches neither `verification_code` nor
 * `issued_at`; and the verify link the student already shared keeps answering
 * exactly as before. A student who read the old sentence had every reason to
 * go and re-send a link that never stopped working — or to tell an employer
 * their credential had been withdrawn.
 *
 * The update is CONDITIONAL on the body still being the shipped text, word for
 * word, on the precedent of `2026_09_23_000600_reword_receipt_approved_for_every_kind`:
 * every template is editable from `/admin`, and replacing an operator's own
 * wording without asking is worse than a sentence they can see and fix.
 *
 * ⚠️ Through the model, never `DB::table()`: `body` is a translatable JSON
 * document since 055, and a raw write stores a bare string that reads as empty.
 * Every channel's row, because the WhatsApp row carries the same body as
 * documentation.
 */
return new class extends Migration
{
    public const SHIPPED_BODY = 'تم إعادة إصدار شهادتك رقم {{ certificate_number }}. النسخة السابقة لم تعد سارية.';

    public const REWORDED_BODY = 'حُدِّثت شهادتك رقم {{ certificate_number }}. رمز التحقّق منها وتاريخ منحها لم يتغيّرا، والرابط الذي شاركته يعمل كما كان.';

    public function up(): void
    {
        MessageTemplate::query()
            ->where('type', NotificationType::CertificateRegenerated->value)
            ->get()
            ->each(function (MessageTemplate $template): void {
                if ($template->body !== self::SHIPPED_BODY) {
                    return;
                }

                $template->forceFill(['body' => self::REWORDED_BODY])->save();
            });
    }

    /** Empty on purpose — restoring a false sentence is not a rollback anyone wants. */
    public function down(): void {}
};
