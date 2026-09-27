<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources;

use App\Modules\Tenancy\Filament\Resources\RoleResource\Pages;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Scopes\TeamRoleScope;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Policies\RolePolicy;
use App\Modules\Tenancy\Support\PermissionLabels;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
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
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
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
                        // The helper text below was the only rule until now: «Course
                        // Reviewer» or «مراجع» saved, and read in code as nothing.
                        ->regex('/^[a-z0-9-]+$/')
                        ->validationMessages(['regex' => 'حروفٌ لاتينيّةٌ صغيرةٌ وأرقامٌ وشَرطاتٌ فقط، مثل course-reviewer.'])
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
                    /*
                    | ⚠️ كلُّ المساحاتِ لمديرِ المنصّةِ وحدَه. من يصلُ الشاشةَ
                    | بـ`roles.manage` يحملُها في **مساحتِه** — فقائمةٌ بكلِّ
                    | المساحاتِ كانت تُنشئُ له دوراً في مساحةِ مدرّسٍ آخر.
                    | والرفضُ مُكرَّرٌ في `CreateRole` لأنّ القائمةَ تُشكِّلُ طلباً
                    | واحداً لا الذي يليه.
                    */
                    Select::make('team_id')
                        ->label('مساحة العمل')
                        ->options(fn (): array => self::workspaceOptions())
                        ->in(fn (): array => array_keys(self::workspaceOptions()))
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
                    | ⚠️ تسميةٌ عربيّةٌ **ثمّ** إخفاؤها — لا `label('')` ولا
                    | `hiddenLabel()` وحدَها.
                    |
                    | النصُّ الفارغُ ليس «بلا تسمية»: Filament يعودُ إلى الاسمِ
                    | المشتقِّ من الحقل، فطُبِعَتْ «Permissions» فوقَ قائمةٍ عربيّة.
                    | و`hiddenLabel()` لا تحذفُ التسميةَ بل تنقلُها إلى
                    | `.fi-sr-only` — فاختفَتْ عن العين **وبقيَ قارئُ الشاشةِ
                    | ينطقُها بالإنجليزيّة**. المرئيُّ ليس كلَّ ما يُقرأ.
                    |
                    | والقسمُ فوقَها معنوَنٌ «الصلاحيات»، فلا داعيَ لتكرارِها بصريّاً.
                    */
                    CheckboxList::make('permissions')
                        ->label('الصلاحيات')
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
                // Only where there is more than one workspace to pick: for a
                // reader under `TeamRoleScope` a filter naming every workspace
                // offered choices that always answered an empty list.
                SelectFilter::make('team_id')
                    ->label('مساحة العمل')
                    ->options(fn (): array => self::workspaceOptions())
                    ->visible(fn (): bool => self::readsEveryWorkspace()),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * ⚠️ THE REFUSAL IS REPEATED HERE, AND ON THE RESPONSE — NOT ON `canDelete()`.
     *
     * `RolePolicy::delete()` refuses a default role, but `AppServiceProvider`'s
     * `Gate::before` answers `true` for the super admin before any policy runs —
     * and the super admin is exactly who stands at this screen. So the row and
     * header delete buttons deleted `teacher` or `tenant-owner` from a workspace,
     * and `SeedDefaultRoles` never runs again to put it back: every member holding
     * it lost everything at once. The same discovery `PlanResource` and
     * `CreditPackageResource` wrote down.
     *
     * ⚠️ AND IT OVERRIDES `getDeleteAuthorizationResponse()`, NOT `canDelete()`.
     * In Filament v5 a `DeleteAction` is authorised by
     * `Page::getDefaultActionAuthorizationResponse()`, which calls this method
     * directly — `canDelete()` is only a wrapper around it, and overriding the
     * wrapper leaves the button working.
     */
    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        if ($record instanceof Role && in_array(
            $record->name,
            [...Roles::workspaceRoles(), ...Roles::platformRoles()],
            true,
        )) {
            return Response::deny('الأدوارُ الافتراضيّةُ وأدوارُ المنصّةِ لا تُحذَف.');
        }

        return parent::getDeleteAuthorizationResponse($record);
    }

    /**
     * ⚠️ EVERY WORKSPACE'S ROLES FOR THE SUPER ADMIN — which is what the
     * «مساحة العمل» column above exists for.
     *
     * `TeamRoleScope` filters by spatie's team id, which the panel's middleware
     * sets from `WorkspaceContext` — and that falls back to `last_workspace_id`.
     * A super admin who has a workspace therefore saw that one workspace's roles
     * under a filter offering every workspace, each choice answering an empty
     * list. `whereNotNull('team_id')` keeps the teamless PLATFORM roles out, as
     * the scope itself does: those are code-owned and edited nowhere.
     *
     * Anybody else here holds `roles.manage` in their own workspace and stays
     * under the scope.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (self::readsEveryWorkspace()) {
            $query->withoutGlobalScope(TeamRoleScope::class)
                ->whereNotNull($query->getModel()->getTable().'.team_id');
        }

        return $query
            ->with(['workspace'])
            ->withCount(['permissions']);
    }

    public static function readsEveryWorkspace(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /**
     * The workspaces a role may be created in or filtered by: all of them for
     * the super admin, the reader's own for anybody else.
     *
     * @return array<int, string>
     */
    public static function workspaceOptions(): array
    {
        $query = Workspace::query()->orderBy('name');

        if (! self::readsEveryWorkspace()) {
            $current = app(WorkspaceContext::class)->id();

            if ($current === null) {
                return [];
            }

            $query->whereKey($current);
        }

        /** @var array<int, string> $options */
        $options = $query->pluck('name', 'id')->all();

        return $options;
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
