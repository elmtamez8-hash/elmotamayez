<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\User;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'الطلبات';
    }

    public static function getModelLabel(): string
    {
        return 'طلب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطلبات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('الطلب')
                    ->description('المبلغُ والمشتري والمقرّرُ ثابتة — تُكتَبُ عندَ إنشاءِ الطلبِ ولا تُعدَّلُ بعدَه.')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('المشتري')
                            ->relationship('user', 'email')
                            ->disabled(),
                        Select::make('course_id')
                            ->label('المقرّر')
                            ->relationship('course', 'title')
                            ->disabled(),
                        TextInput::make('amount_minor')
                            ->label('المبلغ (بالوحدات الصغرى)')
                            ->integer()
                            ->disabled(),
                        TextInput::make('currency')
                            ->label('العملة')
                            ->disabled(),
                    ]),

                Section::make('القرار')
                    ->description('اعتمادُ الطلبِ هو ما يُنشئُ التسجيل — لا تُغيَّرُ الحالةُ إلّا بعدَ التحقّقِ من الإيصال.')
                    ->schema([
                        Select::make('status')
                            ->label('الحالة')
                            ->options(OrderStatus::options())
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('course.title')
                    ->label('المقرّر')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                // ⚠️ بلا بحثٍ ولا ترتيب: `name` سِمةٌ محسوبةٌ لا عمود. {@see CourseResource}
                TextColumn::make('user.name')
                    ->label('اسم المشتري')
                    ->placeholder('—'),
                TextColumn::make('user.email')
                    ->label('بريد المشتري')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('نُسخ البريد'),
                TextColumn::make('kind')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (
                        $state instanceof OrderKind ? $state : OrderKind::tryFrom(is_scalar($state) ? (string) $state : '')
                    )?->label() ?? (is_scalar($state) ? (string) $state : '—')),
                TextColumn::make('amount_minor')
                    ->label('المبلغ')
                    ->money(fn (Order $record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => OrderStatus::labelFor($state))
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'under_review' => 'info',
                        'rejected', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('approver.email')
                    ->label('اعتمده')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الطلب')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(OrderStatus::options()),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }

    /**
     * The credit-purchase cut, repeated here because a TABLE takes no row policy.
     *
     * ⚠️ `OrderPolicy::view()` refuses a credit purchase to anyone without
     * `BILLING_PURCHASE_APPROVE` — a purchase is a sale between the student and the
     * PLATFORM (Q-4), so a teacher holding `ORDERS_VIEW_ALL` may read their own
     * course orders and none of the platform's sales. A Filament list never calls
     * `view()`, so without this the panel handed the teacher every credit total,
     * and two totals across two package sizes solve for the platform's constants —
     * the one inference `billing.collection.view` exists to hold.
     *
     * `OrderController::index()` makes the identical cut in the identical words.
     * Two places, because a list and a record are two different questions, and the
     * one that has no policy behind it is the one that gets forgotten.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Auth::user();

        // ⚠️ `whereIn`, NOT `!= Credits`. The denylist this replaces was correct
        // over an enum of two cases and grew a hole at four: spec 011's store
        // sales and subscriptions would have landed on this table for anyone
        // without the platform permission, which on a panel that admits
        // `assistant-teacher` by role name is the FR-003 breach `viewAny()` was
        // fixed for. One spelling, in the enum — see `teacherListedValues()`.
        if ($user instanceof User && ! $user->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            $query->whereIn('kind', OrderKind::teacherListedValues());
        } else {
            /*
            | ⚠️ 024 — THE FIFTH LAYER OF THE SAME DEFECT, AND THE LIST IS WHERE
            | IT LOOKS LIKE NOTHING IS WRONG.
            |
            | The kind cut above was already right; the workspace was not. This
            | query runs through `BelongsToWorkspace`, and a platform officer's
            | context falls back to `users.last_workspace_id` like everybody
            | else's — so an officer who also owns a workspace saw that
            | workspace's orders and no others, on the one screen whose whole
            | purpose is approving sales across every teacher. No error, no empty
            | state, just a short list that reads as a quiet week.
            |
            | Only for the holder of the platform permission, and the row-level
            | answer is unchanged: `OrderPolicy::view()` still decides what may be
            | opened.
            */
            // `withoutGlobalScope(WorkspaceScope::class)` and not the model's
            // `withoutWorkspaceScope()` helper: Filament's parent hands back a
            // `Builder<Model>`, on which the model's local scope is not typed.
            // The two are the same call — see `BelongsToWorkspace::scopeWithoutWorkspaceScope()`.
            $query->withoutGlobalScope(WorkspaceScope::class);
        }

        return $query->with(['course', 'user', 'approver']);
    }

    public static function getRelations(): array
    {
        return [
            OrderResource\RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
