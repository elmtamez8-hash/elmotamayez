<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Support\PlanShape;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
 * ⚠️ THE TEACHER'S OWN FIELDS ARE READ-ONLY *ON THE EDIT SCREEN*, and the create
 * screen beside it is a different question — 034 · FR-017 gave the platform a
 * second door for WRITING a plan on a named teacher's behalf, which is not the
 * same as rewriting one they wrote. See {@see Pages\CreatePlan}. The row is split between two
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

                    /*
                    | 036 . T074 -- IT IS WHAT THE PLAN SELLS, NOT ITS DURATION.
                    | This is the screen where the platform puts a number on a
                    | teacher's plan, and it read «٠ يوماً» for every plan sold by
                    | sessions: the officer priced twelve lessons believing they
                    | were pricing nothing at all.
                    */
                    Placeholder::make('shape_ro')
                        ->label('ما تبيعه الباقة')
                        ->content(fn (?Plan $record): string => $record === null
                            ? '—'
                            : PlanShape::describe($record->duration_days, $record->session_count) ?? '—'),

                    Placeholder::make('session_type_ro')
                        ->label('نوع الحصص')
                        ->content(fn (?Plan $record): string => $record?->session_type->label() ?? '—'),

                    Placeholder::make('coverage_ro')
                        ->label('التغطية')
                        ->content(fn (?Plan $record): string => $record?->coverage_type->label() ?? '—'),
                ]),

            Section::make('سعر المنصّة')
                ->description('اترُكْه فارغاً فلا تُعرَضُ الباقةُ للبيعِ أصلاً — وهذه هي حالتُها قبلَ التسعير، '
                    .'لا «مجّاناً». بالوحدةِ الصغرى: ٣٠٠ ريال تُكتَبُ 30000.')
                ->columns(1)
                ->schema([
                    /*
                    | ⚠️ 036 . T073 -- RE-CHECKED WHEN THE SHAPE FIELDS LANDED, and
                    | it is unchanged: the field is reachable only through this
                    | Resource, whose `canViewAny()` and `canCreate()` both ask
                    | `plans.price` and nothing else, and both write paths
                    | (`SetPlanPrice` on edit, `CreatePlanForTeacher` on create)
                    | ask it AGAIN, because hiding a control is not a guard. The
                    | new shape fields are the TEACHER's half of the row and open
                    | no door onto this one -- `SavePlan` refuses `price_minor`
                    | from a writer without the permission, measured in
                    | `PlanFormTest`.
                    */
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
                /*
                | 036 -- ONE COLUMN FOR BOTH SHAPES, and `state()` rather than
                | `formatStateUsing` because there is no single column to format:
                | a plan carries a duration or a count, never both. Sorting goes
                | through the model's own `orderedByShape`, so it is dropped here
                | rather than left pointing at a column half the rows leave empty.
                */
                TextColumn::make('shape')->label('ما تبيعه')->placeholder('—')
                    ->state(fn (Plan $record): ?string => PlanShape::describe($record->duration_days, $record->session_count)),
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
                /*
                | ⚠️ THE TEACHER'S THREE ANSWERS, NOT THE COLUMN'S TWO (2026-09-26).
                | «مفعَّلة: نعم» sat here beside a plan whose teacher read «غير
                | معروضة، تنتظر التسعير» — both true, and together they told the
                | officer the plan was on sale when no student could buy it.
                | `isSellable()` is the model's own spelling of «on sale», the one
                | the catalogue and the teacher's screen already read.
                */
                TextColumn::make('sale_state')->label('الحالة')->badge()
                    ->state(fn (Plan $record): string => match (true) {
                        $record->isSellable() => 'معروضة للبيع',
                        $record->is_active => 'بانتظار التسعير',
                        default => 'موقوفة',
                    })
                    ->color(fn (Plan $record): string => match (true) {
                        $record->isSellable() => 'success',
                        $record->is_active => 'warning',
                        default => 'gray',
                    }),
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
            'create' => Pages\CreatePlan::route('/create'),
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

    /**
     * ⚠️ بابٌ ثانٍ لا بديل (٠٣٤ · FR-017): كانَ هذا `false` ومكتوباً تحتَه
     * «تُنشَأُ من شاشةِ المدرّسِ وحدَها» — وهي جملةٌ صارَت تناقضُ ما شُحِن.
     * وبابُ المدرّسِ باقٍ كما هو، وهذا يُضافُ إليه.
     *
     * ⚠️ **ولا صلاحيّةَ ثانية**: مَن يُسعّرُ هو مَن يُنشئُ بالنيابة،
     * واختراعُ ثانيةٍ جوابٌ ثانٍ لسؤالٍ له جواب — وكلُّ إنشاءٍ هنا
     * يضعُ سعراً أصلاً. وتُسأَلُ ثانيةً داخلَ {@see CreatePlanForTeacher}،
     * لأنَّ إخفاءَ زرٍّ ليسَ حراسة.
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::PLANS_PRICE) === true;
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
