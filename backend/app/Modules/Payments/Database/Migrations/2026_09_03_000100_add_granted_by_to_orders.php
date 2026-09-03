<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 024 — من أنشأ الطلبَ حين لم يُنشئْه صاحبُه.
|
| ثلاثةُ أدوارٍ على صفٍّ واحدٍ الآن، وخلطُ أيِّ اثنَين يفقدُ حقيقة:
|   user_id     الطالبُ — صاحبُ الطلبِ والرصيد، حتى حين لم يلمسْ لوحةَ مفاتيح
|   granted_by  الموظّفُ الذي أنشأ الطلبَ نيابةً عنه
|   approved_by الموظّفُ الذي اعتمدَه بعدَ رؤيةِ الإيصال
|
| ⚠️ والحقلانِ الأخيرانِ يُكتبانِ ولو تطابقا (FR-008ب). المواصفةُ تُجيزُ للموظّفِ
| اعتمادَ ما أنشأه، فحقلٌ واحدٌ يجعلُ «أنشأ واعتمدَ وحدَه» و«أنشأ زميلُه واعتمدَ
| هو» غيرَ قابلَين للتمييزِ في أيِّ مراجعةٍ لاحقة — والفرقُ بينهما هو بالضبطِ ما
| يُسألُ عنه يومَ يُسأل.
|
| `null` تعني «الطالبُ اشترى بنفسِه»، وهي الحقيقةُ الصحيحةُ لكلِّ صفٍّ قائم — فلا
| ردمَ هنا ولا قيمةَ افتراضيّة.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            /*
            | لا فهرس: لا استعلامَ يبحثُ بهذا العمود — يُقرَأُ مع صفِّ الطلبِ
            | المقروءِ أصلاً. وفهرسٌ بلا قارئٍ كلفةُ كتابةٍ على أكثرِ جداولِ
            | المنصّةِ نموّاً.
            |
            | `nullOnDelete` لا `cascadeOnDelete`: موظّفٌ يغادرُ المنصّةَ لا يجوزُ
            | أن يمحوَ معه طلبَ طالبٍ ودفعتَه.
            */
            $table->foreignId('granted_by')
                ->nullable()
                ->after('approved_by')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        /*
        | ⚠️ القيدُ يُسقَطُ في عبارةٍ مستقلّةٍ قبلَ العمود.
        |
        | MySQL يتخلّصُ من الفهرسِ المرافقِ للعمودِ ولا يشتكي، بينما `ALTER TABLE
        | … DROP COLUMN` الأصليُّ في SQLite **يرفضُ عموداً مفهرَساً** — وكلُّ
        | اختبارٍ في هذا المستودعِ يعملُ على SQLite في الذاكرة. ومغلَّفانِ
        | منفصلانِ لا واحد، لأنّ تعديلَين في مغلَّفٍ واحدٍ إعادةُ بناءِ جدولٍ هناك.
        */
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('granted_by');
        });
    }
};
