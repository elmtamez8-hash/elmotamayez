<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * حركاتُ الدفعِ على هذا الطلب — قراءةً فقط.
 *
 * ⚠️ سجلٌّ ماليٌّ لا يُحرَّرُ من شاشة. الأسرُ يحدثُ داخلَ `UPDATE` شرطيٍّ واحدٍ
 * يكتبُ `captured_order_id`، وهو عمداً خارجَ `$fillable`: بابٌ ثانٍ لتحريرِه من
 * هنا هو بابٌ ثانٍ لادّعاءِ القفلِ خارجَ المعاملةِ التي تملكُه.
 */
class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    /*
    | ⚠️ `$title` يُسمّي التبويب، وما دونَه يقرأُ `$modelLabel` — وافتراضُه
    | **اسمُ العلاقةِ نفسُه**، فكانت حالةُ الفراغِ تقولُ «لا يوجد transactions» تحتَ
    | تبويبٍ عربيّ.
    */
    protected static ?string $modelLabel = 'حركة دفع';

    protected static ?string $pluralModelLabel = 'حركات الدفع';

    protected static ?string $title = 'حركات الدفع';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('provider')->label('المزوّد')->badge(),
                TextColumn::make('amount_minor')
                    ->label('المبلغ')
                    ->money(fn (PaymentTransaction $record): string => (string) $record->currency, divideBy: 100),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => PaymentStatus::tryFrom($state)?->label() ?? $state)
                    ->color(fn (string $state): string => match (PaymentStatus::tryFrom($state)) {
                        PaymentStatus::Captured => 'success',
                        PaymentStatus::Initiated, PaymentStatus::Pending => 'warning',
                        PaymentStatus::Failed, PaymentStatus::Mismatch, PaymentStatus::Reversed => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('method')->label('الوسيلة')->placeholder('—')->toggleable(),
                TextColumn::make('reference')->label('المرجع')->placeholder('—')->copyable()->toggleable(),
                TextColumn::make('failure_reason')->label('سبب الفشل')->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('settled_at')->label('التسوية')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ]);
    }
}
