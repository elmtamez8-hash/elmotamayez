<?php

namespace Tests;

use Database\Seeders\TestCatalogueSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * الفهارسُ المرجعيّةُ تُبذَرُ مرّةً لكلِّ عمليّةٍ لا مرّةً لكلِّ اختبار.
     *
     * ⚠️ `RefreshDatabase` يقرأُ هذه الخاصّيّةَ في `migrateFreshUsing()` ويُمرِّرُها
     * إلى `migrate:fresh --seeder=…` داخلَ `migrateDatabases()` — أي **قبلَ**
     * `beginDatabaseTransaction()` وقبلَ لقطةِ الـPDO في الذاكرة. فالبذرُ يقعُ
     * مرّةً واحدةً وتبقى صفوفُه أساساً تُلغى فوقَه معاملةُ كلِّ اختبار.
     *
     * انظرْ `TestCatalogueSeeder` لِما تحملُه وسببِ أمانِ النقل.
     */
    protected $seeder = TestCatalogueSeeder::class;
}
