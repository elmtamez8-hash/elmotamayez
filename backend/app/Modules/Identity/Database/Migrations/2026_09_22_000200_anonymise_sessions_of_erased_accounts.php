<?php

declare(strict_types=1);

use App\Modules\Identity\Support\AuthSessionRetention;
use App\Shared\Contracts\LegalHoldDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-014 — مَن مُحِيَ حسابُه قبلَ شحنِ هذه الميزة.
 *
 * ⛔ `IdentityPersonalData::erase()` يرجِعُ `0` قبلَ معاملتِه لحسابٍ يحملُ علامةَ
 * التجهيل. فبلا هذه الهجرةِ لا يصلُ شيءٌ إلى صفوفِ أولئك أبداً: لا طلبَ محوٍ
 * جديدٍ يأتيهم، وذراعُ العمرِ تمسُّ **المنتهيةَ القديمةَ** وحدَها بينما الحسابُ
 * المُجهَّلُ قد تبقى له جلسةٌ نشطة. فيحتفظونَ بعناوينِهم وبصماتِهم إلى الأبد.
 *
 * ⚠️ **والجسدُ ليس هنا بل في {@see AuthSessionRetention::backfillErasedAccounts()}**
 * — وهذا قرارٌ لا ترتيب: الهجرةُ تعملُ داخلَ `migrate:fresh` قبلَ وجودِ صفٍّ
 * واحد، فجسدٌ مكتوبٌ هنا **لا يراه اختبارٌ في الشجرةِ كلِّها**، ومزالقُه الثلاثةُ
 * (`chunkById` لا `chunk` · `DB::table()` لا نموذج · `uuid` وطوابعُ وقتٍ صريحة)
 * تبقى بلا تغطية.
 *
 * ⚠️ **ولا يُكتَبُ عبرَ نموذج**: ترحيلٌ يتكلّمُ مخطَّطَ تاريخِه ونموذجٌ يتكلّمُ
 * اليوم — و`2026_08_29_003100_backfill_referral_catalogue_action` أسقطَ ٦٩٠ من
 * ٦٩٠ اختباراً بهذا عينِه.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | ⚠️ قارئُ الحظرِ خامٌ هنا عمداً: الربطُ في الحاوية يقرأُ عبرَ نموذجِ
        | `LegalHold`، والهجرةُ تتكلّمُ مخطَّطَ تاريخِها. `released_at IS NULL` هو
        | `LegalHold::scopeInForce()` مكتوباً بلا نموذج.
        */
        (new AuthSessionRetention)->backfillErasedAccounts(new class implements LegalHoldDirectory
        {
            /** @return list<int> */
            public function heldUserIds(): array
            {
                $ids = [];

                foreach (DB::table('legal_holds')->whereNull('released_at')->distinct()->pluck('subject_user_id') as $id) {
                    $ids[] = (int) $id;
                }

                return $ids;
            }
        });
    }

    /*
    | ⚠️ لا تراجُعَ ولا يُدَّعى. التجهيلُ لا يُعكَس: العنوانُ والبصمةُ ذهبا، ولا
    | نسخةَ منهما في مكانٍ آخر. و`down()` يُعيدُ بناءَ ما لا يُبنى هو الكذبُ الذي
    | يُسجِّلُه هذا المستودَعُ عن العمودِ الذي يعودُ فارغاً فيُقرَأُ بياناتٍ.
    */
    public function down(): void {}
};
