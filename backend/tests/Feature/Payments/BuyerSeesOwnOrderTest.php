<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ «طلباتي» تُقرَأُ بملكيّةِ الصفِّ لا بمساحةِ العمل.
|
| قِيسَ على الإنتاج ٢٠٢٦-٠٩-١٦ بمشيٍ حقيقيّ: طالبٌ اشترى اشتراكاً بثلاثِ مئةِ
| ريالٍ من مدرّسٍ في مساحةِ العملِ ٥، وهو مختومٌ بـ`last_workspace_id = 1` — فتحَ
| «طلباتي» فقرأَ «لا طلبات في سجلّك». قائمةٌ فارغةٌ بلا خطأٍ واحد، ومعها يختفي
| زرُّ رفعِ الإيصالِ وموضعُ قراءةِ القرار.
|
| ⚠️ **والختمُ ليسَ حالةً نادرة**: `addWorkspaceMember` و`AcceptInvitation`
| والباذرانِ يكتبونَ ذلك العمود، وقِيسَت ستُّ عضويّاتٍ بدَورِ `student` على قاعدةٍ
| حقيقيّة. والطالبُ المُسجِّلُ نفسَه بنفسِه وحدَه هو مَن سياقُه فارغٌ فالنطاقُ
| خاملٌ معه — وهو شكلٌ لا يرى هذا العطبَ أبداً.
|
| ⚠️ **ولذلك الختمُ بـ`forceFill`**: `last_workspace_id` في `User::$guarded`،
| فـ`factory()->create([...])` يُسقِطُه بصمتٍ ويُعيدُ بناءَ الطالبِ ذي السياقِ
| الفارغِ — أي يمرُّ الاختبارُ فوقَ البناءِ المعطوبِ ويُقرَأُ حارساً.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->mine, $this->owner] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الشراء']);
    [$this->elsewhere, $this->other] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);

    $this->buyer = User::factory()->create();
    $this->buyer->forceFill(['last_workspace_id' => $this->elsewhere->getKey()])->save();

    $this->order = Order::create([
        'workspace_id' => $this->mine->getKey(),
        'user_id' => $this->buyer->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 30_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);
});

it('shows a buyer the order they placed with another teacher', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $this->order->uuid);
});

/*
| ⚠️ الشقُّ الضدُّ، وبلا هذا الملفُّ يمرُّ على بناءٍ ألغى النطاقَ للجميع: فرعُ
| المدرّسِ يبقى مُنطَّقاً لأنّ النطاقَ **هو** حارسُه هناك — لا مُرشِّحَ ملكيّةٍ
| تحتَه، فإلغاؤُه يُسلِّمُ كلَّ مدرّسٍ طلباتِ كلِّ مدرّس.
*/
it('still keeps another workspace’s orders away from a teacher', function (): void {
    $this->setCurrentWorkspace($this->elsewhere, $this->other);

    Sanctum::actingAs($this->other);

    expect($this->other->can(Permissions::ORDERS_VIEW_ALL))->toBeTrue()
        ->and($this->other->can(Permissions::BILLING_PURCHASE_APPROVE))->toBeFalse();

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
| ⚠️ وموظّفُ المنصّةِ يملكُ مساحةَ عملٍ خاصّةً به — وهو السطرُ الذي كشفَ طبقاتِ
| ٠٢٤ الخمس. بدونِه يرتدُّ سياقُه إلى لا شيءٍ فيبدو المُنطَّقُ سليماً.
*/
it('gives a platform officer the platform, not their own workspace', function (): void {
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $officer->forceFill(['last_workspace_id' => $this->elsewhere->getKey()])->save();

    Sanctum::actingAs($officer);

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $this->order->uuid);
});
