<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ٠٣٧ · قصّة ٢ — بابُ اللوحةِ يُسجَّلُ كما يُسجَّلُ بابُ الواجهة.
|
| `token_id` هو مِقبَضُ الإنهاءِ للجلسةِ التي دخلَت من الـAPI. ودخولُ `/admin` لا
| يُنتِجُ رمزاً أصلاً — لا ينبغي أن يُنتِجَ: رمزٌ يُسَكُّ عندَ فتحِ اللوحةِ اعتمادٌ
| لم يطلبْه أحدٌ، ويُصادِقُ الـAPI لو تسرّب. فمِقبَضُها معرِّفُ جلستِها.
|
| ⚠️ **والعمودُ قابلٌ للفراغِ كما `token_id`، ولا أحدَ منهما يُغني عن الآخَر**:
| صفٌّ من الـAPI يحملُ الأوّلَ وحدَه، وصفٌّ من اللوحةِ يحملُ الثانيَ وحدَه.
| و`TerminateAuthSession` يقرأُ الاثنَين، فلا يبقى صفٌّ بلا مِقبَضٍ يُنهيه.
|
| ⚠️ **وطولُ اسمِ الفهرسِ مقصود**: `auth_sessions_session_id_index` = ٣٠ حرفاً،
| دونَ سقفِ MySQL (٦٤). تجاوزُه يُسقِطُ الهجرةَ على الإنتاجِ **وحدَه** — SQLite لا
| سقفَ فيه، فلا شريحةَ من شرائحِ CI تراه. حرسَه
| `SchemaIdentifierLengthTest` بعدَ أن كلّفَ نشرةً كاملة.
|
| ⚠️ **و٤٠ حرفاً هو طولُ معرِّفِ جلسةِ Laravel** (`Str::random(40)`)، و٦٤ هنا
| هامشٌ لإعدادٍ يُغيِّرُه أحدٌ لاحقاً — لا رقمٌ اعتباطيّ.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->string('session_id', 64)->nullable()->after('token_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            // ⚠️ الفهرسُ أوّلاً وفي جملةٍ مستقلّة: MySQL يُسقِطُ فهرسَ العمودِ معَه
            // بلا شكوى، بينما SQLite **يرفضُ** إسقاطَ عمودٍ مفهرَس — وكلُّ اختبارٍ
            // في هذا المستودَعِ يعملُ على SQLite.
            $table->dropIndex(['session_id']);
        });

        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->dropColumn('session_id');
        });
    }
};
