<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٦ — قالبا قرارِ تعديلِ الباقةِ يصلانِ قاعدةً قائمة.
 *
 * ⛔ **وبلا هذا الترحيلِ يسقطُ القراران في صمتٍ والاختباراتُ كلُّها خضر** —
 * سادسُ مرّةٍ تُشحَنُ هذه الآليّةُ في هذه الشجرة. `TemplateRenderer` يرفضُ
 * قالباً غائباً و`DispatchNotification` **يسجّلُ ولا يفشل**، و`tests/Pest.php`
 * يزرعُ الفهرسَ قبلَ كلِّ حالةٍ فلا يراه اختبارٌ واحد. والمدرّسُ الذي انتظرَ
 * قرارَ الإدارةِ في باقتِه لا يعلمُ أنّه صدر.
 *
 * و`seedMissing()` لا `run()`: كلُّ صفٍّ قابلٌ للتحريرِ من `/admin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /** فارغةٌ عمداً — انظر سابقاتِها. */
    public function down(): void {}
};
