<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;

/**
 * The link request names the page the way the STUDENT's sidebar does.
 *
 * It goes to the student, and told them to open «المرتبطون» — the guardian's
 * name for `/family`; the student's sidebar reads «من يتابعني» (parent journey
 * audit 2026-10-21). Conditional on the shipped text, through the model, every
 * channel's row — exactly as `2026_09_27_000100_reword_credit_balance_dormant_to_contact_support`.
 */
return new class extends Migration
{
    public const SHIPPED_BODY = 'طلب {{ name }} الارتباط بحسابك بصفة {{ relation_type }}. افتح صفحة «المرتبطون» لقراءة ما سيطّلع عليه قبل أن تقبل أو ترفض.';

    public const REWORDED_BODY = 'طلب {{ name }} الارتباط بحسابك بصفة {{ relation_type }}. افتح صفحة «من يتابعني» لقراءة ما سيطّلع عليه قبل أن تقبل أو ترفض.';

    public function up(): void
    {
        MessageTemplate::query()
            ->where('type', NotificationType::GuardianLinkRequested->value)
            ->get()
            ->each(function (MessageTemplate $template): void {
                if ($template->body === self::SHIPPED_BODY) {
                    $template->forceFill(['body' => self::REWORDED_BODY])->save();
                }
            });
    }

    public function down(): void {}
};
