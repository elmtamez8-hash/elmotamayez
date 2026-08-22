<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\User;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'under_review' => 'Under Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->required(),
                TextInput::make('amount_minor')
                    ->integer()
                    ->disabled(),
                TextInput::make('currency')
                    ->disabled(),
                Select::make('user_id')
                    ->relationship('user', 'email')
                    ->disabled(),
                Select::make('course_id')
                    ->relationship('course', 'title')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.title')->searchable()->sortable(),
                TextColumn::make('user.email')->searchable(),
                TextColumn::make('amount_minor')->money(fn (Order $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'under_review' => 'info',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'under_review' => 'Under Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
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

        if ($user instanceof User && ! $user->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            $query->where('kind', '!=', OrderKind::Credits->value);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
