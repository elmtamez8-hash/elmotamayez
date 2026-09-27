<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources;

use App\Filament\NavigationGroups;
use App\Filament\Support\MoneyInput;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Payments\Filament\Resources\CouponResource\Pages;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\MinorUnits;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The platform's discount codes (spec 011 · T068 · FR-010).
 *
 * ⚠️ NO TENANT ROLE REACHES THIS SCREEN, THE WORKSPACE OWNER INCLUDED. A coupon
 * comes out of the platform's own commission, so a teacher who could write one
 * would be spending money that is not theirs — and `teacher_net_minor` is
 * computed from the LIST price precisely so their side never moves. The guard is
 * `CouponPolicy`, so this screen and any future endpoint answer the same
 * question with the same code.
 *
 * ⚠️ THE WORKSPACE FIELD IS LEFT EMPTY FOR A PLATFORM-WIDE CODE, and empty is the
 * ordinary case. Filling it narrows the code to one teacher's products — it is a
 * SCOPE, not an owner, and it is why this model deliberately carries no
 * `BelongsToWorkspace`.
 *
 * ⚠️ AND DELETION IS REFUSED HERE AS WELL AS IN THE POLICY. `BasePolicy::before()`
 * waves a super admin past every policy method, and a super admin is exactly who
 * is standing at this screen: the refusal that keeps the BUTTON off the page is
 * this one. Retirement is `is_active = false`, which the resolver reads on the
 * next purchase; the row stays because every `coupon_redemptions` entry made
 * from it is FR-015's record of a discount somebody actually received.
 */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroups::MONEY;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return 'الكوبونات';
    }

    public static function getModelLabel(): string
    {
        return 'كوبون';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الكوبونات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الكود وقيمته')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('الكود')
                        ->required()
                        ->maxLength(32)
                        ->unique(ignoreRecord: true)
                        // Normalised here as well as in the model: the panel is a
                        // second writer, and a code stored lower-case would never
                        // be found by a resolver that upper-cases what it is given.
                        ->dehydrateStateUsing(fn (string $state): string => Coupon::normaliseCode($state))
                        ->helperText('يُحوَّل إلى أحرفٍ كبيرةٍ تلقائيّاً، والمشتري يكتبه كيفما شاء.'),

                    Select::make('value_kind')
                        ->label('نوع الخصم')
                        ->required()
                        ->live()
                        ->default(CouponValueKind::Percent->value)
                        ->options(fn (): array => collect(CouponValueKind::cases())
                            ->mapWithKeys(fn (CouponValueKind $kind): array => [$kind->value => $kind->label()])
                            ->all()),

                    /*
                    | ⛔ عمودٌ واحدٌ بمعنيَين: نسبةٌ صحيحةٌ من ١ إلى ١٠٠، أو مبلغٌ
                    | بالوحدةِ الصغرى. كانَ الحقلُ رقماً حرّاً يقبلُ ١٥٠٪ ويطلبُ
                    | المبلغَ بالهللات. الآن النسبةُ عددٌ صحيحٌ بسقفِ ١٠٠، والمبلغُ
                    | يُكتَبُ بالوحدةِ الكبرى (قرارُ المالك ٢٠٢٦-٠٩-٢٧) ويُخزَّنُ بالصغرى.
                    | الملءُ يسألُ الصفَّ المخزَّن، والحفظُ يسألُ النوعَ المختارَ الآن.
                    */
                    TextInput::make('value')
                        ->label('القيمة')
                        ->numeric()
                        ->required()
                        ->minValue(fn (Get $get): int|float => self::isFixed($get('value_kind')) ? 0.01 : 1)
                        ->maxValue(fn (Get $get): int => self::isFixed($get('value_kind')) ? 1_000_000 : 100)
                        ->rule(fn (Get $get): string => self::isFixed($get('value_kind')) ? 'decimal:0,2' : 'integer')
                        ->suffix(fn (Get $get): string => self::isFixed($get('value_kind'))
                            ? MoneyInput::currencyLabel(app(BillingSettings::class)->currency())
                            : '٪')
                        ->formatStateUsing(fn (mixed $state, ?Coupon $record): mixed => $record?->value_kind === CouponValueKind::FixedMinor && is_numeric($state)
                            ? MinorUnits::toMajor((int) $state)
                            : $state)
                        ->dehydrateStateUsing(fn (mixed $state, Get $get): ?int => self::isFixed($get('value_kind'))
                            ? MinorUnits::fromMajor($state)
                            : (is_numeric($state) ? (int) $state : null))
                        ->helperText('نسبة: عددٌ صحيحٌ من ١ إلى ١٠٠. مبلغ ثابت: بالعملة نفسِها (٤٩٫٩٩ تُكتَبُ 49.99)، '
                            .'ويُقَصّ عند قيمة السطر فلا يهبط المبلغُ تحت الصفر.'),
                ]),

            Section::make('النطاق')
                ->description('اتركِ الحقلين فارغين ليسريَ الكوبون على كلِّ شيء. وتحديدُ مساحةِ عملٍ يقصره '
                    .'على مدرّسٍ واحد؛ وتحديدُ نوعٍ ومعرِّفٍ يقصره على شيءٍ واحدٍ بعينه.')
                ->columns(3)
                ->schema([
                    /*
                    | ⚠️ كانَ رقماً يُكتَبُ باليد بلا مفتاحٍ أجنبيّ: رقمٌ خاطئٌ يكتبُ
                    | كوبوناً لا يسري عند أحد، بلا خطأ. الآن اسمُ المدرّس.
                    */
                    Select::make('workspace_id')
                        ->label('المدرّس / مساحة العمل')
                        ->options(fn (): array => Workspace::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('scope_uuid', null))
                        ->placeholder('المنصّة كلّها')
                        ->helperText('فارغ = كوبون منصّة يسري عند كلّ مدرّس.'),

                    Select::make('scope_type')
                        ->label('النوع')
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('scope_uuid', null))
                        ->placeholder('كل شيء')
                        ->options(fn (): array => collect(CouponScope::cases())
                            ->mapWithKeys(fn (CouponScope $scope): array => [$scope->value => $scope->label()])
                            ->all()),

                    /*
                    | ⚠️ كانَ نصّاً يُلصَقُ فيه uuid: خطأٌ حرفٌ واحدٌ يكتبُ كوبوناً لا
                    | يطابقُ شيئاً. الآن قائمةٌ بالنوعِ المختار، وبمدرّسِ الكوبونِ إن
                    | حُدِّد. وبتجاوزِ النطاق: الموظَّفُ ليسَ عضواً في مساحةِ المدرّس،
                    | فقائمةٌ مقيَّدةٌ تعرضُ كورساتِه هو أو لا شيء — كما في `CreatePlan`.
                    */
                    Select::make('scope_uuid')
                        ->label(fn (Get $get): string => match (self::scope($get('scope_type'))) {
                            CouponScope::Course => 'الكورس',
                            CouponScope::StoreItem => 'المنتج',
                            CouponScope::CreditPackage => 'الحزمة',
                            null => 'العنصر',
                        })
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => self::scopeTargets(
                            self::scope($get('scope_type')),
                            self::workspaceId($get('workspace_id')),
                            $search,
                        ))
                        ->getOptionLabelUsing(fn (mixed $value, Get $get): ?string => is_string($value)
                            ? self::scopeTargetLabel(self::scope($get('scope_type')), $value)
                            : null)
                        ->visible(fn (Get $get): bool => self::scope($get('scope_type')) !== null)
                        ->required(fn (Get $get): bool => self::scope($get('scope_type')) !== null)
                        ->helperText('اكتب جزءاً من الاسم للبحث.'),
                ]),

            Section::make('المدّة والسقف')
                ->columns(2)
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('يبدأ')
                        ->seconds(false)
                        ->helperText('فارغ = ساري من الآن.'),

                    DateTimePicker::make('ends_at')
                        ->label('ينتهي')
                        ->seconds(false)
                        // The column is a TIMESTAMP and this picker writes one, so
                        // the last day is whole. A date field here would bind
                        // midnight and kill the coupon on the morning of its own
                        // final day.
                        ->helperText('فارغ = بلا انتهاء. والوقت محسوبٌ باللحظة، فآخرُ يومٍ يومٌ كامل.'),

                    TextInput::make('max_redemptions')
                        ->label('سقف الاستعمال')
                        ->integer()
                        ->minValue(1)
                        ->helperText('فارغ = بلا سقف. والسقفُ لا يُتجاوَز مهما تزامنت المحاولات.'),

                    Toggle::make('is_active')
                        ->label('فعّال')
                        ->default(true)
                        ->helperText('إيقافه يمنع أيَّ استعمالٍ جديدٍ ولا يمسّ خصماً مُنِح.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->label('الكود')->searchable()->sortable(),
                TextColumn::make('value')->label('القيمة')->formatStateUsing(
                    fn (mixed $state, Coupon $record): string => $record->value_kind === CouponValueKind::Percent
                        ? (int) $state.'٪'
                        : MinorUnits::toMajor((int) $state).' '.MoneyInput::currencyLabel(app(BillingSettings::class)->currency()),
                ),
                TextColumn::make('scope_type')->label('النطاق')->badge()
                    ->placeholder('كل شيء')
                    ->formatStateUsing(fn (mixed $state): string => self::scope($state)?->label() ?? 'كل شيء'),
                TextColumn::make('workspace.name')->label('المدرّس')
                    ->placeholder('المنصّة'),
                // Read side by side on purpose: «١٢ من ٥٠» is the question an
                // operator actually has, and two separate columns make them
                // compare numbers on different rows of the same screen.
                TextColumn::make('redemptions_count')->label('الاستعمال')->sortable()
                    ->formatStateUsing(fn (int $state, Coupon $record): string => $record->max_redemptions === null
                        ? $state.' (بلا سقف)'
                        : $state.' من '.$record->max_redemptions),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->sortable()
                    ->placeholder('بلا انتهاء'),
                IconColumn::make('is_active')->label('فعّال')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('فعّال'),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }

    /**
     * The panel's second factor for a coupon write — the sentence
     * `TwoFactorMandate` gives the API, told rather than hidden.
     *
     * ⚠️ `/admin` IS SESSION-AUTHENTICATED AND NEVER PASSES THROUGH
     * `2fa.required`, so a coupon — money out of the platform's own commission —
     * was a money write an overdue account could still make. Asked by both pages
     * that write (`CreateCoupon`, `EditCoupon`), the way `OrderResource` asks
     * before every decision.
     */
    public static function refusedForTwoFactor(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ($refusal = TwoFactorMandate::refusalFor($actor)) === null) {
            return false;
        }

        Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

        return true;
    }

    /** النوعُ يصلُ كائنَ enum من الصبِّ عندَ التعديل، ونصّاً من الاختيار. */
    public static function isFixed(mixed $kind): bool
    {
        return ($kind instanceof CouponValueKind ? $kind : CouponValueKind::tryFrom(is_string($kind) ? $kind : ''))
            === CouponValueKind::FixedMinor;
    }

    private static function scope(mixed $scope): ?CouponScope
    {
        if ($scope instanceof CouponScope) {
            return $scope;
        }

        return is_string($scope) ? CouponScope::tryFrom($scope) : null;
    }

    private static function workspaceId(mixed $workspace): ?int
    {
        return is_numeric($workspace) ? (int) $workspace : null;
    }

    /**
     * What a code may be narrowed to, uuid => label, for the chosen kind.
     *
     * ⚠️ `withoutWorkspaceScope()` on both tenant models, and the chosen teacher
     * as an explicit `where` instead — the officer at this screen is a member of
     * no teacher's workspace, so a scoped read would offer their own rows or
     * nothing. A platform-wide code (no teacher chosen) names the teacher in the
     * label, since two teachers may both sell «الفيزياء ١».
     *
     * @return array<string, string>
     */
    public static function scopeTargets(?CouponScope $scope, ?int $workspaceId, string $search = ''): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

        return match ($scope) {
            CouponScope::Course => Course::query()
                ->withoutWorkspaceScope()
                ->with('workspace:id,name')
                ->when($workspaceId !== null, fn (Builder $query): Builder => $query->where('workspace_id', $workspaceId))
                ->where('title', 'like', $like)
                ->orderBy('title')
                ->limit(50)
                ->get(['uuid', 'title', 'workspace_id'])
                ->mapWithKeys(fn (Course $course): array => [
                    (string) $course->uuid => $workspaceId === null && $course->workspace !== null
                        ? $course->title.' — '.$course->workspace->name
                        : $course->title,
                ])
                ->all(),
            CouponScope::StoreItem => StoreItem::query()
                ->withoutWorkspaceScope()
                ->with('workspace:id,name')
                ->when($workspaceId !== null, fn (Builder $query): Builder => $query->where('workspace_id', $workspaceId))
                ->where('title', 'like', $like)
                ->orderBy('title')
                ->limit(50)
                ->get(['uuid', 'title', 'workspace_id'])
                ->mapWithKeys(fn (StoreItem $item): array => [
                    (string) $item->uuid => $workspaceId === null && $item->workspace !== null
                        ? $item->title.' — '.$item->workspace->name
                        : $item->title,
                ])
                ->all(),
            // Platform-owned: a package belongs to no teacher, so the chosen
            // workspace narrows nothing here.
            CouponScope::CreditPackage => CreditPackage::query()
                ->where('name', 'like', $like)
                ->orderBy('sort_order')
                ->limit(50)
                ->pluck('name', 'uuid')
                ->all(),
            null => [],
        };
    }

    /** The label of the stored target — a deleted or unknown one keeps its uuid rather than going blank. */
    public static function scopeTargetLabel(?CouponScope $scope, string $uuid): string
    {
        $label = match ($scope) {
            CouponScope::Course => Course::query()->withoutWorkspaceScope()->withTrashed()->where('uuid', $uuid)->value('title'),
            CouponScope::StoreItem => StoreItem::query()->withoutWorkspaceScope()->where('uuid', $uuid)->value('title'),
            CouponScope::CreditPackage => CreditPackage::query()->where('uuid', $uuid)->value('name'),
            null => null,
        };

        return is_string($label) && $label !== '' ? $label : $uuid;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
