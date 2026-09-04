<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Models\User;
use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Analytics\Support\Money;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * الطلابُ والمال — الأعلى دفعاً والمتعثِّرون، في جدولٍ واحدٍ بمرشِّحٍ واحد.
 *
 * ⛔ **الرصيدُ السالبُ بالحصصِ لا بالمال، ولا يُحوَّلُ.** ثمنُ الحصّةِ سعرُ
 * مدرّسِها المعتمَد، فمجموعُ ديونٍ عبرَ مدرّسينَ بمبلغٍ واحدٍ يفترضُ سعراً واحداً
 * لا وجودَ له. الحصصُ هي الوحدةُ التي يقبلُ المجموعُ فيها الجمع.
 *
 * ⚠️ **والمدفوعُ مجموعٌ بالعملة**، فحقلُ «الأعلى دفعاً» يُرتَّبُ على الوحداتِ
 * الصغرى مجموعةً كما هي — وهو ترتيبٌ صحيحٌ ما دامت منصّةً بعملةٍ سائدة، وسطرُ
 * العرضِ يُظهِرُ التفصيلَ بالعملةِ حتّى لا يُقرَأَ الرقمُ على أنّه عملةٌ واحدة.
 *
 * ⚠️ **والمتعثّرُ من رصيدُه سالبٌ، لا من `credit_limit` صفرٌ عندَه**: السقفُ
 * ينزلُ إلى الصفرِ عندَ التخفيضِ بينما يبقى الرصيدُ سالباً، فقراءةُ السقفِ وحدَه
 * تُخرِجُ من القائمةِ الشخصَ الذي وُضِعَت لأجلِه — نفسُ عطلِ `isBlocked`.
 *
 * ⚠️ **و`negative_since` هو عمرُ الدَّين** لا تاريخُ آخرِ حركة: الثاني يتحرّكُ مع
 * كلِّ كتابةٍ على الصفّ، فعمرٌ مقيسٌ منه عمرٌ يُصفِّرُ نفسَه.
 */
class StudentMoneyWidget extends BaseWidget
{
    use PlatformWideWidget;

    protected static ?int $sort = 7;

    protected static ?string $heading = 'الطلاب والمال';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->students())
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('name')->label('الطالب')->searchable(['first_name', 'last_name']),
                TextColumn::make('country')->label('البلد')->placeholder('غير محدَّد')->toggleable(),
                TextColumn::make('paid_minor')
                    ->label('المدفوع')
                    ->formatStateUsing(fn (User $record): string => Money::line($this->paidBy($record), '٠٫٠٠'))
                    ->sortable(),
                TextColumn::make('owed_credits')
                    ->label('الرصيد السالب')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (int) $state === 0 ? '—' : number_format(abs((int) $state)).' حصّة')
                    ->color(fn (mixed $state): string => (int) $state < 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('overdue_since')
                    ->label('متأخّر منذ')
                    ->date('Y-m-d')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('orders_count')->label('طلبات معتمَدة')->sortable(),
            ])
            ->filters([
                SelectFilter::make('lens')
                    ->label('العدسة')
                    ->options(['payers' => 'الأعلى دفعاً', 'defaulters' => 'المتعثّرون في الدفع'])
                    ->default('payers')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'payers') {
                        'defaulters' => $query->whereExists(fn ($sub) => $sub->from('credit_balances')
                            ->whereColumn('credit_balances.student_user_id', 'users.id')
                            ->whereRaw('CAST(credit_balances.remaining_credits AS SIGNED) < 0'))
                            ->orderBy('owed_credits'),
                        default => $query->orderByDesc('paid_minor'),
                    }),
                SelectFilter::make('country')
                    ->label('البلد')
                    ->options(fn (): array => User::query()->whereNotNull('country')
                        ->distinct()->orderBy('country')->pluck('country', 'country')->all()),
            ]);
    }

    /** @return Builder<User> */
    private function students(): Builder
    {
        $captured = PaymentTransaction::query()->withoutWorkspaceScope()
            ->join('orders', 'orders.id', '=', 'payment_transactions.order_id')
            ->whereColumn('orders.user_id', 'users.id')
            ->where('payment_transactions.status', 'captured');

        return User::query()
            ->where('platform_role', 'student')
            ->addSelect(['paid_minor' => (clone $captured)
                ->selectRaw('COALESCE(SUM(payment_transactions.amount_minor), 0)')->limit(1)])
            // ⚠️ CAST إلى SIGNED: الأعمدةُ غيرُ مُوقَّعةٍ على MySQL، وجمعُ سالبٍ
            // عليها يرفعُ ERROR 1690 — ولا حسابَ غيرَ مُوقَّعٍ في SQLite ليُظهِرَه.
            ->addSelect(['owed_credits' => CreditBalance::query()->withoutWorkspaceScope()
                ->whereColumn('credit_balances.student_user_id', 'users.id')
                ->whereRaw('CAST(credit_balances.remaining_credits AS SIGNED) < 0')
                ->selectRaw('COALESCE(SUM(CAST(remaining_credits AS SIGNED)), 0)')->limit(1)])
            ->addSelect(['overdue_since' => CreditBalance::query()->withoutWorkspaceScope()
                ->whereColumn('credit_balances.student_user_id', 'users.id')
                ->whereNotNull('negative_since')
                ->selectRaw('MIN(negative_since)')->limit(1)])
            ->addSelect(['orders_count' => Order::query()->withoutWorkspaceScope()
                ->whereColumn('orders.user_id', 'users.id')
                ->where('orders.status', 'approved')
                ->selectRaw('COUNT(*)')->limit(1)]);
    }

    /**
     * تفصيلُ ما دفعَه هذا الطالب، بالعملة.
     *
     * @return list<array{currency: string, total: int}>
     */
    private function paidBy(User $student): array
    {
        // ⚠️ `array_values` على المُخرَجِ النهائيّ: `->values()->all()` يُعيدُ
        // `array<int, …>` في نظرِ المُحلِّل، وهو ليس `list` بالضرورة.
        return array_values(PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->join('orders', 'orders.id', '=', 'payment_transactions.order_id')
            ->where('orders.user_id', $student->getKey())
            ->where('payment_transactions.status', 'captured')
            ->groupBy('payment_transactions.currency')
            /*
            | ⚠️ `select ... as total` ثمّ `getAttribute`، ولا `pluck` على تعبيرٍ
            | خام: `pluck` يشتقُّ اسمَ الخاصّيّةِ من نصِّ العمود، فيقرأُ من
            | `SUM(payment_transactions.amount_minor)` الاسمَ `amount_minor` —
            | وهو ليس اسمَ أيِّ عمودٍ في الناتج، فيرمي «Undefined property»
            | داخلَ تصييرِ الجدول. قِيسَ في هذا الملفِّ نفسِه قبلَ الشحن.
            */
            ->select(
                'payment_transactions.currency',
                DB::raw('SUM(payment_transactions.amount_minor) as total'),
            )
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) $row->getAttribute('currency'),
                'total' => (int) $row->getAttribute('total'),
            ])
            ->all());
    }
}
