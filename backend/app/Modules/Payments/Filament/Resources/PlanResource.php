<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The platform's pricing queue for subscription plans (011 · US4 · FR-025 · Q4).
 *
 * ⚠️ WITHOUT THIS SCREEN THE WHOLE OF US4 IS UNREACHABLE, and the failure is
 * silent all the way down: an unpriced plan is not `sellable()`, so the student's
 * catalogue is empty, so nothing is bought, so nothing activates. Everything
 * below it works perfectly and nobody can get to it. That is the shape this
 * repository has now recorded three times — `taxonomy.manage` declared and read
 * by no file, `writeBans.lift` with an endpoint and no button, FR-046's thread
 * behind a uuid nobody was shown — and `PATCH /admin/plans/{uuid}/price` reachable
 * only by curl is the fourth.
 *
 * ⚠️ THE TEACHER'S OWN FIELDS ARE READ-ONLY HERE. The row is split between two
 * actors by FR-025: the teacher writes the duration and the coverage, the
 * platform writes the price. An officer who could rewrite «كلّ كورساتي» into «كورس
 * واحد» would be editing what a teacher is selling, which is not what this screen
 * is for — and the API enforces the mirror of it (`SavePlan` refuses
 * `price_minor` from anyone without the platform permission).
 *
 * ⚠️ `canViewAny()` IS THE PLATFORM PERMISSION ALONE, NOT THE POLICY'S `viewAny`.
 * That policy method admits `plans.manage` too, because the teacher's own API
 * list goes through it — but a Filament LIST never calls the row policy, so a
 * teacher admitted here would read every other teacher's plans with an edit
 * button beside each. The same discovery `OrderResource` already had to make.
 *
 * ⚠️ AND THE QUERY DECLARES `withoutWorkspaceScope()`. `WorkspaceContext::id()`
 * falls back to `users.last_workspace_id` for a platform officer exactly as for
 * anybody else, so a scoped list silently shows one arbitrary teacher's plans as
 * though they were the whole queue — and passes its own test on a
 * single-workspace fixture.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return 'تسعير باقات الاشتراك';
    }

    public static function getModelLabel(): string
    {
        return 'باقة اشتراك';
    }

    public static function getPluralModelLabel(): string
    {
        return 'باقات الاشتراك';
    }

    /**
     * How many plans are still waiting for a number.
     *
     * The badge is the whole point of the queue: a teacher who created a plan
     * this morning has a screen that says «تنتظر تسعير المنصّة» and no way to
     * chase it, so the only thing standing between them and a sale is somebody
     * here noticing.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Plan::query()
            ->withoutWorkspaceScope()
            ->where('is_active', true)
            ->whereNull('price_minor')
            ->count();

        return $waiting === 0 ? null : (string) $waiting;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Builder<Plan> */
    public static function getEloquentQuery(): Builder
    {
        // See the class docblock — a scoped queue is a queue that covers one
        // teacher and says nothing about it. Built from the model rather than
        // from `parent::getEloquentQuery()`, whose declared return type is a
        // builder over the base `Model` and therefore knows nothing of the
        // trait's bypass.
        return Plan::query()->withoutWorkspaceScope()->with('workspace');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ما كتبه المدرّس')
                ->description('هذه الحقولُ للمدرّس، لا للمنصّة (FR-025): هو يحدّدُ المدّةَ وما تغطّيه، '
                    .'والمنصّةُ تحدّدُ السعرَ وحدَه. تُعرَضُ هنا لتُسعَّرَ على أساسِها، ولا تُحرَّرُ منها.')
                ->columns(2)
                ->schema([
                    Placeholder::make('title_ro')
                        ->label('الباقة')
                        ->content(fn (?Plan $record): string => (string) $record?->title),

                    Placeholder::make('workspace_ro')
                        ->label('المدرّس')
                        ->content(fn (?Plan $record): string => (string) $record?->workspace?->name),

                    Placeholder::make('duration_ro')
                        ->label('المدّة')
                        ->content(fn (?Plan $record): string => $record === null
                            ? '—'
                            : $record->duration_days.' يوماً'),

                    Placeholder::make('session_type_ro')
                        ->label('نوع الحصص')
                        ->content(fn (?Plan $record): string => $record?->session_type->label() ?? '—'),

                    Placeholder::make('coverage_ro')
                        ->label('التغطية')
                        ->content(fn (?Plan $record): string => $record?->coverage_type->label() ?? '—'),
                ]),

            Section::make('سعر المنصّة')
                ->description('اترُكْه فارغاً فلا تُعرَضُ الباقةُ للبيعِ أصلاً — وهذه هي حالتُها قبلَ التسعير، '
                    .'لا «مجّاناً». بالوحدةِ الصغرى: ٣٠٠٫٠٠ ريالاً تُكتَبُ 30000.')
                ->columns(1)
                ->schema([
                    TextInput::make('price_minor')
                        ->label('السعر بالوحدة الصغرى')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('تغييرُ السعرِ لا يمسُّ اشتراكاً جارياً: كلُّ اشتراكٍ يحملُ لقطةَ سعرِه '
                            .'من لحظةِ الشراء (FR-030).'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Unpriced first: this screen exists to empty that queue.
            ->defaultSort('price_minor')
            ->columns([
                TextColumn::make('title')->label('الباقة')->searchable()->sortable(),
                TextColumn::make('workspace.name')->label('المدرّس')->searchable(),
                TextColumn::make('duration_days')->label('المدّة')->sortable()
                    ->formatStateUsing(fn (int $state): string => $state.' يوماً'),
                TextColumn::make('session_type')->label('النوع')->badge()
                    ->formatStateUsing(fn (ClassSessionType $state): string => $state->label()),
                TextColumn::make('coverage_type')->label('التغطية')
                    ->formatStateUsing(fn (PlanCoverage $state): string => $state->label()),
                TextColumn::make('price_minor')->label('السعر')->sortable()
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? 'تنتظر التسعير'
                        : number_format($state / 100, 2))
                    ->badge()
                    ->color(fn (?int $state): string => $state === null ? 'warning' : 'gray'),
                IconColumn::make('is_active')->label('مفعَّلة')->boolean(),
            ])
            ->filters([
                Filter::make('unpriced')
                    ->label('تنتظر التسعير')
                    ->query(fn (Builder $query): Builder => $query->whereNull('price_minor')),

                SelectFilter::make('session_type')
                    ->label('نوع الحصص')
                    ->options(fn (): array => collect(ClassSessionType::cases())
                        ->mapWithKeys(fn (ClassSessionType $type): array => [$type->value => $type->label()])
                        ->all()),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }

    /**
     * ⚠️ THE PLATFORM PERMISSION ALONE — see the class docblock. `PlanPolicy`
     * admits `plans.manage` as well, because the teacher's own API list is
     * authorised through it, and a Filament list never consults the row policy.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::PLANS_PRICE) === true;
    }

    /** A plan is created by its teacher, in their own screen. Never here. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Retire with `is_active`, never delete — `subscriptions.plan_id` points here
     * and a student's own subscription must keep naming what they bought.
     *
     * Repeated on the Resource because `BasePolicy::before()` waves a super admin
     * past every policy method, and a super admin is exactly who is standing at
     * this screen. The discovery `CreditPackageResource` already wrote down.
     */
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
