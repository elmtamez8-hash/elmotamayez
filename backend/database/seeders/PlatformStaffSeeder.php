<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\Seeder;

/**
 * موظّفٌ ماليٌّ واحدٌ على قاعدةِ التطوير.
 *
 * ⚠️ `platform_staff` كانَ فارغاً تماماً — صفرَ صفوفٍ — على كلِّ قاعدةِ تطويرٍ
 * يُنشِئُها `migrate:fresh --seed`، فلا موظّفَ ماليٌّ يستطيعُ فتحَ
 * `‎/admin/grant-credit-subscription` أصلاً، ونصفُ مشيِ ٠٢٧ اليدويِّ (US2 · US3)
 * محجوزٌ على بياناتٍ غائبةٍ لا على شيفرةٍ ناقصة. عائلةُ جدولِ `plans` الفارغِ
 * نفسُها، وقد كلّفَ الاكتشافُ الأوّلُ مشياً كاملاً.
 *
 * ⚠️ ويحملُها **مالكُ مساحةِ عمل**، وهذا هو الشرطُ الحامل. `WorkspaceContext::id()`
 * ترتدُّ إلى `users.last_workspace_id` لكلِّ مستخدِمٍ بمن فيهم موظّفُ المنصّة، فموظّفٌ
 * بلا مساحةِ عملٍ يمرُّ أخضرَ فوقَ العطلِ الخماسيِّ الذي كشفَه ٠٢٤ — الربطُ بالمسارِ
 * والسياسةُ والتحديثاتُ الشرطيّةُ والإشعارُ ولوحةُ Filament، خمستُها. وسطرٌ واحدٌ
 * يُضيفُ `last_workspace_id` إلى الموظّفِ هو الذي كشفَها جميعاً.
 *
 * ⚠️ ولا حسابَ جديدٌ ولا كلمةُ مرورٍ جديدة: الوقوفُ للمنصّةِ صفٌّ يُضافُ إلى شخصٍ
 * قائم، وحسابٌ ثانٍ بكلمةٍ مطبوعةٍ في سجلِّ الباذرِ شيءٌ لا يطلبُه أحد.
 *
 * ⚠️ ويُستدعى من {@see DatabaseSeeder} داخلَ حارسِ «ليس الإنتاج» وحدَه. منحُ
 * صلاحيّةِ اعتمادِ المدفوعاتِ لا يكونُ باذراً على قاعدةٍ حيّة؛ بابُه هناك
 * `PlatformStaffResource` بيدِ مديرِ المنصّة.
 */
class PlatformStaffSeeder extends Seeder
{
    public function run(): void
    {
        $officer = User::query()->where('email', 'teacher@example.com')->first();
        $grantor = User::query()->where('is_super_admin', true)->first();

        if ($officer === null || $grantor === null) {
            $this->command->warn('PlatformStaffSeeder: لا مدرّس تجريبيّ أو لا مدير منصّة — تُخطّى.');

            return;
        }

        // ⚠️ عبرَ النموذجِ لا `insertOrIgnore`: الثانيةُ تكتبُ صفّاً بلا إقلاعِ
        // النموذج، فلا يعملُ `HasUuid`، وMySQL يخفّضُ انتهاكَ NOT NULL إلى تحذيرٍ
        // ويخزّنُ `''` — ثمّ يصطدمُ كلُّ صفٍّ لاحقٍ بذلك الصفِّ على `unique(uuid)`.
        $standing = PlatformStaff::query()->firstOrCreate(
            ['user_id' => $officer->getKey(), 'role' => Roles::FINANCE_ADMIN],
            [
                'assigned_by' => $grantor->getKey(),
                'reason' => 'بيانات تطوير: موظّف ماليّ للمشي اليدويّ على طابور الطلبات.',
            ],
        );

        // الهجاءُ نفسُه الذي في `CreatePlatformStaff`: الوقوفُ للمنصّةِ يبدأُ ساعةَ
        // التحقّقِ بخطوتَين، وإلّا كانَ `2fa.required` على مسارَي الاعتمادِ والرفضِ
        // يحرسُ لا أحد. و`applyTo()` لا يُعيدُ منحَ مهلةٍ لمن له موعدٌ سلفاً.
        TwoFactorMandate::applyTo($standing->user);

        $this->command->info('Finance officer: teacher@example.com (finance-admin) — /admin/grant-credit-subscription');
    }
}
