<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources;

use App\Modules\Tenancy\Filament\Resources\RoleResource\Pages;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Policies\RolePolicy;
use App\Modules\Tenancy\Support\PermissionLabels;
use App\Modules\Tenancy\Support\Roles;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * شاشةُ الأدوار — بدلَ الشاشةِ التي كانت تأتي من حزمةِ Shield.
 *
 * ⚠️ **العيبُ الذي أزالَتْه**: للسياقِ الفارغِ يُبطِلُ `TeamRoleScope` تصفيتَه عن
 * قصد (لا مساحةَ عمل ⇒ لا شرط)، فيرى مديرُ المنصّةِ أدوارَ كلِّ المساحات — وشاشةُ
 * الحزمةِ لا عمودَ فيها يقولُ لمن كلُّ صفّ. فكان «Tenant Owner» يظهرُ ثلاثَ مرّاتٍ
 * متطابقةً في الشكل، وتعديلُ الصفِّ الخطأ يُعيدُ ترتيبَ سلطةِ مدرّسٍ آخَر. العمودُ
 * «مساحة العمل» هو كلُّ الفرق.
 *
 * ⚠️ **ولماذا مورِدٌ من عندِنا لا تهيئةٌ للحزمة**: أسماءُ الأدوارِ كانت تُطبَعُ
 * بـ`Str::headline` («Assistant Teacher»)، والصلاحيّاتُ تُعرَضُ بأسمائِها الخامّةِ
 * (`billing.collection.view`) — بينما `PermissionLabels` مكتوبٌ عندنا منذُ سبيك
 * ٠١٠ لهذا الغرضِ بالضبط **ولا يقرؤه أحد**: خريطةُ عربيّةٍ لا تصلُ شاشة. وتبويبا
 * النموذجِ «Permissions» و«Custom permissions» سلسلتانِ حرفيّتانِ في PHP الحزمةِ
 * بلا مفتاحِ ترجمةٍ يُتجاوَز.
 *
 * ⚠️ **ولا باب هنا.** {@see RolePolicy} يحملُ
 * `viewAny` و`create` و`update` و`delete` كلَّها، وتصريحُ `canViewAny()` فوقَها
 * إجابةٌ ثانيةٌ لسؤالٍ واحد — وهو الخطأُ الذي فتحَ شاشةَ تفويضاتِ المنصّةِ لمالكِ
 * مساحةِ عملٍ صباحَ اليوم. البابُ سياسة، والفحصُ يقبلُ أيَّ الاثنَين لا كلَيهما.
 *
 * @extends \Filament\Resources\Resource<Model>
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'إدارة الوصول';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'الأدوار';
    }

    public static function getModelLabel(): string
    {
        return 'دور';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الأدوار';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الدور')
                ->description('الاسمُ مفتاحُ سلطةٍ يُقرأُ في الشيفرة، ومساحةُ العملِ تُحدَّدُ مرّةً ولا تُنقَل.')
                ->columns(2)
                ->schema([
                    /*
                    | ⚠️ الاسمُ يُعطَّلُ للأدوارِ الافتراضيّة، ويُسقَطُ من البيانات
                    | في `EditRole` أيضاً: حقلٌ معطَّلٌ حاجزُ واجهةٍ لا حاجزُ كتابة،
                    | وطلبُ Livewire مصنوعٌ باليدِ يتجاوزُه. وإعادةُ تسميةِ
                    | `teacher` تكسرُ كلَّ `hasRole('teacher')` في الشجرة،
                    | و`SeedDefaultRoles` لا يُعيدُه — وهو سببُ رفضِ الحذفِ نفسُه.
                    */
                    TextInput::make('name')
                        ->label('الاسم')
                        ->required()
                        ->maxLength(125)
                        ->helperText('حروفٌ لاتينيّةٌ وشَرطات، مثل `course-reviewer`. يُقرأ في الشيفرة ولا يُترجَم.')
                        ->disabled(fn (?Role $record): bool => $record !== null
                            && in_array($record->name, Roles::workspaceRoles(), true))
                        ->rule(fn (?Role $record) => Rule::unique('roles', 'name')
                            ->where('team_id', $record?->getAttribute('team_id'))
                            ->where('guard_name', 'web')
                            ->ignore($record?->getKey())),

                    /*
                    | ⚠️ مساحةُ العملِ تُختارُ صراحةً ولا تُترَكُ لـspatie.
                    |
                    | الحزمةُ تملأُ `team_id` من مُسجِّلِ الصلاحيّات، وهو `null`
                    | لكلِّ من يستطيعُ بلوغَ هذه الشاشةِ أصلاً (مديرُ المنصّةِ
                    | وموظّفوها) — فكانت «إضافة دور» تُنشئُ صفّاً بلا مساحة، أي
                    | **دورَ منصّة**، ثمّ يُخفيه `TeamRoleScope` فوراً: صفٌّ يُكتَبُ
                    | ولا يُرى ولا يُحذَف.
                    */
                    Select::make('team_id')
                        ->label('مساحة العمل')
                        ->options(fn (): array => Workspace::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->disabled(fn (?Role $record): bool => $record !== null)
                        ->helperText('الدورُ يعيشُ داخلَ مساحةٍ واحدة، ولا يُنقَلُ بعدَ إنشائِه.'),
                ]),

            Section::make('الصلاحيات')
                ->description('صلاحيّاتُ المنصّةِ غيرُ معروضةٍ هنا بحال: دورٌ داخلَ مساحةِ عملٍ لا يحملُها، ويرفضُها النموذجُ نفسُه لا هذا النموذج.')
                ->schema([
                    /*
                    | ⚠️ **بلا `->relationship()`**، وهذه أهمُّ سطرٍ في الملفّ.
                    |
                    | العلاقةُ المباشرةُ تجعلُ Filament يكتبُ على جدولِ الوصلِ بنفسِه،
                    | فيتجاوزُ `Role::syncPermissions()` — وهو الجدارُ الذي يمنعُ
                    | صلاحيّةَ منصّةٍ أن تصلَ دورَ مساحةِ عمل، ويمسحُ ذاكرةَ spatie
                    | معاً. الحفظُ يمرُّ من `EditRole::handleRecordUpdate()`.
                    |
                    | والخياراتُ من `PermissionLabels::tenantMap()`: خريطةٌ كُتبَتْ
                    | في سبيك ٠١٠ لهذه الشاشةِ بالذات ولم يقرأْها أحد، فبقيَتِ
                    | الصلاحيّاتُ تُعرَضُ `billing.collection.view` خامّةً.
                    */
                    /*
                    | ⚠️ `hiddenLabel()` لا `label('')`. النصُّ الفارغُ ليس «بلا
                    | تسمية»: Filament يعودُ إلى الاسمِ المشتقِّ من الحقل، فظهرَتْ
                    | كلمةُ «Permissions» بالإنجليزيّةِ فوقَ قائمةٍ عربيّةٍ
                    | بالكامل. والقسمُ فوقَها معنوَنٌ «الصلاحيات» أصلاً.
                    |
                    | ولا يراه `PanelIsArabicTest`: النصُّ المعروضُ مُولَّدٌ وقتَ
                    | التشغيلِ من اسمِ الحقل، والفحصُ يقرأُ ما هو مكتوبٌ في الملفّ.
                    */
                    CheckboxList::make('permissions')
                        ->hiddenLabel()
                        ->options(PermissionLabels::tenantMap())
                        ->columns(3)
                        ->bulkToggleable()
                        ->searchable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('الدور')
                    ->formatStateUsing(fn (string $state): string => Roles::label($state))
                    ->description(fn (Role $record): string => $record->name)
                    ->searchable()
                    ->sortable(),
                /*
                | العمودُ الذي كان غائباً — وغيابُه هو العيب. بلا إغلاقٍ لكلِّ صفّ:
                | التحميلُ المسبقُ في `getEloquentQuery()`.
                */
                TextColumn::make('workspace.name')
                    ->label('مساحة العمل')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label('عدد الصلاحيات')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('آخر تعديل')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('team_id')
                    ->label('مساحة العمل')
                    ->options(fn (): array => Workspace::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['workspace'])
            ->withCount(['permissions']);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
