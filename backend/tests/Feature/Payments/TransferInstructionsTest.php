<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Support\PlatformSettings;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ إلى أينَ يُحوِّلُ المشتري — بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-١٦.
|
| شاشةُ الاشتراكِ تقولُ «حوِّلْ قيمة الباقة إلى حساب المنصّة، ثمّ ارفعْ صورة
| التحويل» — ولا تقولُ أيُّ حساب. وقِيسَ على الإنتاج أنّ `platform_settings` فيه
| تسعةٌ وستّونَ صفّاً ليسَ فيها اسمُ بنكٍ ولا آيبان ولا محفظة: المنتَجُ يطلبُ
| تحويلاً إلى مكانٍ غيرِ معلَن.
*/

it('answers with what the operator wrote, and drops what they left empty', function (): void {
    PlatformSettings::set('billing.transfer', [
        'bank_name' => 'بنك قطر الوطني',
        'account_name' => 'المتميز',
        'iban' => 'QA58DOHB00001234567890ABCDEFG',
        'account_number' => '',
        'wallet_label' => '',
        'wallet_number' => '',
        'note' => 'اكتب اسمك في خانة الملاحظات.',
    ]);

    Sanctum::actingAs(User::factory()->create());

    $body = $this->getJson('/api/v1/billing/transfer-instructions')->assertOk();

    expect($body->json('configured'))->toBeTrue()
        ->and($body->json('data.bank_name'))->toBe('بنك قطر الوطني')
        ->and($body->json('data.iban'))->toBe('QA58DOHB00001234567890ABCDEFG')
        // ⚠️ الخانةُ الفارغةُ تُسقَطُ ولا تُرسَلُ سلسلةً فارغة: الشاشةُ ترسمُ ما
        // يصلُها، وسطرُ «المحفظة: » بلا رقمٍ يُقرَأُ بياناتٍ لم تُحمَّل.
        ->and($body->json('data'))->not->toHaveKey('wallet_number')
        ->and($body->json('data'))->not->toHaveKey('account_number');
});

/*
| ⚠️ **اسمُ بنكٍ بلا رقمٍ ليسَ عنواناً يُحوَّلُ إليه.** مشغِّلٌ ملأَ نصفَ القسمِ
| ونسيَ الباقيَ يترُكُ المشتريَ حيثُ كان، والشاشةُ تحتاجُ أن تعرفَ الفرقَ حتّى
| تقولَ الجملةَ الصريحةَ بدلَ صندوقٍ نصفِ فارغٍ يبدو مكتملاً.
*/
it('is not «configured» on a name with no number behind it', function (): void {
    PlatformSettings::set('billing.transfer', ['bank_name' => 'بنك قطر الوطني']);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/billing/transfer-instructions')
        ->assertOk()
        ->assertJsonPath('configured', false);
});

it('says «not configured» rather than failing when nothing was ever written', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/billing/transfer-instructions')
        ->assertOk()
        ->assertJsonPath('configured', false)
        ->assertJsonPath('data', []);
});

/*
| ⚠️ **ولا تُضافُ إلى `/platform` العامّ.** ذاك قائمةُ سماحٍ بحقلٍ **واحد**،
| وتعليقُه يقولُ إنّ كلَّ حقلٍ يُضافُ إليه ينضمُّ صامتاً إلى عنوانٍ عامّ. وهذه
| الحالةُ هي ما يمنعُ أن تُوضَعَ هناك «تسهيلاً» يوماً.
*/
it('keeps the destination off the public identity endpoint', function (): void {
    PlatformSettings::set('billing.transfer', ['iban' => 'QA58DOHB00001234567890ABCDEFG']);

    $body = $this->getJson('/api/v1/platform')->assertOk();

    expect(array_keys((array) $body->json('data')))->toBe(['name']);
});

it('refuses the destination to somebody with no account', function (): void {
    $this->getJson('/api/v1/billing/transfer-instructions')->assertUnauthorized();
});
