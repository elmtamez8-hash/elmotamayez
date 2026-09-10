<?php

declare(strict_types=1);

use App\Filament\Resources\MessageTemplateResource\Pages\EditMessageTemplate;
use App\Models\User;
use App\Modules\Gamification\Filament\Resources\BadgeResource\Pages\EditBadge;
use App\Modules\Gamification\Filament\Resources\GamificationActionResource\Pages\EditGamificationAction;
use App\Modules\Gamification\Filament\Resources\LevelResource\Pages\EditLevel;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\Level;
use App\Modules\Marketplace\Filament\Resources\GradeLevelResource\Pages\EditGradeLevel;
use App\Modules\Marketplace\Filament\Resources\RegionResource\Pages\EditRegion;
use App\Modules\Marketplace\Filament\Resources\SchoolYearResource\Pages\EditSchoolYear;
use App\Modules\Marketplace\Filament\Resources\SubjectResource\Pages\EditSubject;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Notifications\Models\MessageTemplate;
use Livewire\Livewire;

/*
| ٠٥٥ · النموذجُ يُملأ من `attributesToArray()` — والعمودُ المترجَمُ مستند.
|
| ⚠️ **عيبٌ يُتلفُ البيانات، لا عيبَ شكل، ووُجِدَ بفتحِ الشاشةِ على الإنتاج.**
| `EditRecord::fillForm()` يملأُ من `attributesToArray()`، وspatie تُعيدُ منه
| المستندَ كاملاً — فاستقبلَ `TextInput` مصفوفةً وطبعَ المتصفّحُ النصَّ الحرفيَّ
| `[object Object]`. واضغطْ «حفظ» دونَ أن تلمسَ الحقلَ فذاكَ ما يُحفَظ: المادّةُ
| تُسمَّى `[object Object]` للجميع، من نموذجٍ لم يُعدّلْه أحد.
|
| ⚠️ **والجدولُ كانَ صحيحاً طَوالَ الوقت** — عمودُ الجدولِ يقرأُ الـaccessor الذي
| يُجيبُ بلغةٍ واحدة — ولذلك لم يبدُ شيءٌ خاطئاً حتّى فُتِحَ نموذجُ تعديل. أيُّ
| اختبارٍ يقيسُ القائمةَ وحدَها يمرُّ أخضرَ فوقَ هذا العطبِ تماماً.
|
| ⚠️ **وحالةُ الملءِ وحدَها هي الحارس، وقِيسَ ذلك بالحذف.** بتعطيلِ الـtrait
| سقطَت ثمانٍ من ستّ عشرةَ — الملءُ كلُّه — ونجحَ الحفظُ كلُّه: اختبارُ Livewire
| بلا متصفّحٍ يُحوِّلُ الكائنَ نصّاً، فالمصفوفةُ تصلُ سليمةً إلى `setTranslations`
| ويبقى الصفُّ صحيحاً. **فالنصفُ الثاني من العطبِ — تحويلُ JavaScript — لا
| يراهُ PHP بحال**، وادّعاءُ خلافِ ذلك أسوأُ من غيابِ الاختبار.
|
| فالحالةُ الثانيةُ تسألُ سؤالاً آخرَ لها فيه جواب: تعديلٌ حقيقيٌّ يكتبُ تحتَ
| مفتاحِ اللغةِ الجارية **ولا يمسُّ لغةً أخرى على الصفِّ نفسِه** — وهو ما يبيتُّ
| إن أضافَ أحدٌ يوماً نظيراً لهذا الـtrait على مسارِ الحفظ.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
});

dataset('translatable edit forms', [
    /*
    | ⚠️ صفوفٌ **مبذورة**، لا `factory()`. الكتالوجاتُ الخمسةُ تُبذَرُ قبلَ كلِّ
    | اختبارٍ في `TestCatalogueSeeder`، فمصنعٌ هنا يصطدمُ بمفتاحٍ فريدٍ موجودٍ
    | أصلاً — `unique(levels.level)` سقطَ على CI ومرَّ محلّيّاً بالصدفة. وهي
    | كذلك الصفوفُ التي يفتحُها المشغّلُ فعلاً.
    */
    'مادّة' => [EditSubject::class, fn (): Subject => Subject::query()->firstOrFail(), 'name'],
    'مرحلة' => [EditGradeLevel::class, fn (): GradeLevel => GradeLevel::query()->firstOrFail(), 'name'],
    'منطقة' => [EditRegion::class, fn (): Region => Region::query()->firstOrFail(), 'name'],
    'صفّ دراسيّ' => [EditSchoolYear::class, fn (): SchoolYear => SchoolYear::query()->firstOrFail(), 'name'],
    'فعل تلعيب' => [EditGamificationAction::class, fn (): GamificationAction => GamificationAction::query()->firstOrFail(), 'name'],
    'مستوى' => [EditLevel::class, fn (): Level => Level::query()->firstOrFail(), 'name'],
    'شارة' => [EditBadge::class, fn (): Badge => Badge::query()->firstOrFail(), 'name'],
    'قالب رسالة' => [EditMessageTemplate::class, fn (): MessageTemplate => MessageTemplate::query()->firstOrFail(), 'title'],
]);

it('fills the form with the locale string, never the document', function (
    string $page,
    Closure $make,
    string $field,
): void {
    $record = $make();
    $expected = $record->{$field};

    // Or the assertion below could be comparing nothing with nothing.
    expect($expected)->toBeString()->not->toBe('');

    Livewire::test($page, ['record' => $record->getRouteKey()])
        ->assertFormSet([$field => $expected]);
})->with('translatable edit forms');

it('writes the edited locale and leaves another language on the row alone', function (
    string $page,
    Closure $make,
    string $field,
): void {
    $record = $make();

    // A second language nobody is editing. Today nothing writes one; the guard
    // is for the day something does, because a save-side counterpart to this
    // trait written the obvious way would replace the whole document.
    $record->setTranslation($field, 'en', 'UNTOUCHED-SENTINEL');
    $record->save();

    Livewire::test($page, ['record' => $record->getRouteKey()])
        ->fillForm([$field => 'قيمةٌ جديدة'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $record->fresh();

    expect($fresh->getTranslation($field, 'ar'))->toBe('قيمةٌ جديدة')
        ->and($fresh->getTranslation($field, 'en'))->toBe('UNTOUCHED-SENTINEL');
})->with('translatable edit forms');
