<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WorkspaceResource\Pages;
use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Actions\SetMarketplaceParticipation;
use App\Modules\Tenancy\Enums\WorkspaceType;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * أماكنُ العمل — كلُّ مكانٍ مدرّس. لمدير المنصّة، بإنشاءٍ **ولا تعديلَ ولا حذف**.
 *
 * ⚠️ هذه الشاشةُ هي البابُ الوحيدُ الباقي بعدَ مواصفةِ ٠٢٥ (FR-009). المكانُ يُولَدُ
 * مع حسابِ المدرّسِ نفسِه، و`POST /workspaces` يُجيبُ `403` لكلِّ من لا يحملُ
 * `workspaces.create` — وهي صلاحيّةٌ في لا دورِ مستأجرٍ واحد. فإن احتاجَ مدرّسٌ
 * مكاناً لسببٍ تشغيليّ، هنا وحدَها.
 *
 * ⚠️ و`handleRecordCreation` تنادي `CreateWorkspace`، ولا تدعُ Filament يستدعي
 * `Workspace::create()`. الفرقُ ليس أسلوباً: `WorkspaceCreated` هو ما يُطلِقُ
 * `SeedDefaultRoles`، ومساحةٌ تُكتَبُ بإدراجٍ مباشرٍ تُولَدُ بلا دورٍ واحد وتبقى كذلك
 * للأبد. والدليلُ مقيسٌ لا مفترَض: صفُّ «المنصة» على الإنتاجِ كُتِبَ بـ`forceFill`
 * ولم يحملْ دوراً واحداً منذُ ذلك اليوم.
 *
 * ⚠️ ولا حذفَ: الصفُّ مشارٌ إليه من كلِّ جدولٍ مقسَّمٍ بـ`workspace_id` في المنتَج.
 *
 * ⚠️ ولوحةُ `/admin` مستثناةٌ من FR-012 صراحةً: شاشةٌ تُنشئُ مكانَ عملٍ لا تستطيعُ
 * ألّا تسمّيَ ما تُنشِئُه، وهي أداةُ مديرِ المنصّةِ لا واجهةُ المنتَج.
 */
class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'أماكن العمل';
    }

    public static function getModelLabel(): string
    {
        return 'مكان عمل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أماكن العمل';
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('create', Workspace::class) ?? false;
    }

    /**
     * ⚠️ مُنتقي مالكٍ، لأنّ المسارَ الخلفيَّ لا يُشبِعُ FR-009 إطلاقاً:
     * `WorkspaceController::store()` يجعلُ **المنادِيَ** هو المالك، فمديرُ منصّةٍ
     * ينشئُ «لمدرّسٍ» عبرَ الـAPI يملكُها هو.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('مكان عمل جديد')
                ->description('يُنشأ مع حساب المدرّس تلقائيًا. هذه الشاشة للحالات التشغيلية وحدها، ويُسجَّل الفعل باسمك.')
                ->columns(2)
                ->schema([
                    Select::make('owner_user_id')
                        ->label('المدرّس')
                        /*
                        | ⚠️ الخياراتُ تُبنى بـ`name` المُلحَقِ لا بعمودٍ باسمِه — لا
                        | عمودَ `name` في `users`، بل مُلحَقٌ فوقَ `first_name` و
                        | `last_name`، وعنوانُ علاقةٍ باسمِه يُترجَمُ إلى
                        | `select users.name` فتسقطُ الصفحةُ بـ500.
                        */
                        ->options(fn (): array => User::query()
                            ->where('platform_role', PlatformRole::Teacher)
                            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                                ->from('workspaces')
                                ->whereColumn('workspaces.owner_user_id', 'users.id'))
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn (User $u): array => [$u->getKey() => $u->name.' — '.$u->email])
                            ->all())
                        ->searchable()
                        ->required()
                        /*
                        | القائمةُ تحملُ من يجوزُ اختيارُه وحدَهم: مدرّسٌ لا يملكُ
                        | مكاناً. وهذا **ليس** هو الحارس — `CreateWorkspace` يرفضُ
                        | الاثنَين بنفسِه (FR-004 · FR-008)، لأنّ قائمةً مُرشَّحةً
                        | تُشكِّلُ طلباً واحداً ولا تُشكِّلُ الذي بعدَه.
                        */
                        ->helperText('المدرّسون الذين لا يملكون مكانًا بعد. لا يظهر هنا طالب ولا وليّ أمر.'),

                    TextInput::make('name')
                        ->label('الاسم')
                        ->required()
                        ->maxLength(255)
                        ->helperText('يُشتق عادةً من اسم المدرّس.'),
                ]),
        ]);
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * ⚠️ العدّانِ استعلامٌ واحدٌ لكلِّ صفحة، لا استعلامٌ لكلِّ صفّ. مورِدُ Filament
     * يُنفَّذُ مرّةً لكلِّ صفّ، فأيُّ `->count()` داخلَ عمودٍ هو N+1 بالتعريف.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['members']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('slug')->label('المُعرِّف')->searchable()->toggleable(),
                TextColumn::make('type')->label('النوع')->badge()
                    ->formatStateUsing(fn (string $state): string => WorkspaceType::labelFor($state)),
                TextColumn::make('owner.email')
                    ->label('المالك')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('members_count')
                    ->label('الأعضاء')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                IconColumn::make('participates_in_marketplace')
                    ->label('في السوق')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('أُنشئت في')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordActions([
                self::participationAction(),
            ]);
    }

    /**
     * إدراجُ مكانِ العملِ في السوقِ العامِّ أو سحبُه منه.
     *
     * ⚠️ **الصلاحيّةُ موجودةٌ والإجراءُ موجودٌ والمسارُ موجود — ولم يكنْ لأيٍّ منها
     * بابٌ يُضغَط.** `PUT /workspace/marketplace-participation` لا يُناديه ملفٌّ
     * واحدٌ تحتَ `frontend/src`، ولم تكنْ له شاشةٌ في اللوحة: فمكانُ عملٍ خارجَ
     * السوقِ يُخفي كلَّ مدرّسيه وكورساتِه ومقالاتِه **بلا وسيلةٍ لأحدٍ أن يُرجِعَه**.
     * قِيسَ على الإنتاجِ ٢٠٢٦-٠٩-١٣: مكانانِ خارجَ السوقِ وفيهما كورسانِ منشورانِ
     * وخمسةُ طلّابٍ مسجّلين. وهي رابعُ مرّةٍ في هذه الشجرة: `settlement.requestRate`
     * و`PUT /teacher/availability` و`completeLesson` كلُّها كانت أبواباً خلفيّةً بلا
     * مُنادٍ.
     *
     * ⚠️ **ويمرُّ بالإجراءِ لا بكتابةِ العمود.** {@see SetMarketplaceParticipation}
     * يُفرِغُ {@see MarketplaceCache} بعدَ الكتابة، وتبديلٌ يكتبُ العمودَ مباشرةً
     * يتركُ السوقَ يعرضُ الجوابَ القديمَ حتّى تنتهي مدّةُ الخبء — أي زرٌّ «نجحَ»
     * ولا شيءَ يتغيّرُ على الصفحة.
     *
     * ⚠️ **والصلاحيّةُ تُسألُ هنا صراحةً.** قائمةُ Filament لا تستدعي سياسةَ الصفِّ
     * أبداً، و`Gate::before` يُمرِّرُ المشرِفَ العامَّ فوقَ كلِّ سياسة — فحارسٌ
     * مكتوبٌ في سياسةٍ وحدَها حارسٌ لا يعملُ على هذه الشاشة.
     *
     * ⚠️ **وتأكيدٌ لأنّ السحبَ واسعُ الأثر**: مدرّسو المكانِ وكورساتُه ومدوّنتُه
     * تختفي كلُّها من السوقِ في ضغطةٍ واحدة، ولا شيءَ في الجدولِ يقولُ ذلك.
     */
    public static function participationAction(): Action
    {
        return Action::make('participation')
            ->label(fn (Workspace $record): string => $record->participates_in_marketplace
                ? 'اسحبْ من السوق'
                : 'أدرِجْ في السوق')
            ->icon(fn (Workspace $record): Heroicon => $record->participates_in_marketplace
                ? Heroicon::OutlinedEyeSlash
                : Heroicon::OutlinedGlobeAlt)
            ->color(fn (Workspace $record): string => $record->participates_in_marketplace ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (Workspace $record): string => $record->participates_in_marketplace
                ? 'يختفي مدرّسو هذا المكان وكورساتُه ومقالاتُه من السوق العامّ فورًا. لا يتغيّر اعتمادُ أيِّ مدرّس، ولا يفقد طالبٌ مسجَّلٌ شيئًا.'
                : 'يظهر مدرّسو هذا المكان المعتمَدون وكورساتُهم ومقالاتُهم في السوق العامّ فورًا.')
            ->visible(fn (): bool => Auth::user()?->can(Permissions::MARKETPLACE_PARTICIPATION_MANAGE) ?? false)
            ->action(fn (Workspace $record) => app(SetMarketplaceParticipation::class)
                ->handle($record, ! $record->participates_in_marketplace));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkspaces::route('/'),
            'create' => Pages\CreateWorkspace::route('/create'),
        ];
    }
}
