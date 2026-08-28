<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\RewardResource\RelationManagers;

use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Gamification\Models\Redemption;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * طلباتُ الاستبدالِ على هذه المكافأة — للقراءة فقط.
 *
 * ⚠️ لا إنشاءَ ولا تعديلَ ولا حذفَ ولا ربطَ ولا فكَّ ربط. الانتقالُ من «قيد
 * التنفيذ» يجري بجملةِ UPDATE شرطيّةٍ واحدةٍ داخل `Gamification\Actions\DecideRedemption`: صفٌّ
 * يُكتب من استمارةٍ هنا يتجاوز تلك الجملة، وضغطتا «رفض» تقرآن كلتاهما `pending`
 * فتُعيدان العملاتِ مرّتين — عملاتٌ من العدم، وهي الجهةُ التي لا يحرسها FR-034
 * لأنّه مكتوبٌ عن الرصيدِ حين يهبط تحت الصفر. والحذفُ أسوأ: صفُّ الاستبدال هو ما
 * يعرف الشهرَ الذي استُهلك عدّادُه، فبلا وجودِه تضيع وحدةُ مخزونٍ إلى الأبد.
 *
 * ولا بحثَ في أيِّ عمود: `redemptions` لا تحمل فهرساً على `reward_id`، وما يجعل
 * هذا الجدولَ محتملاً هو `(workspace_id, status, created_at)` — يركبه النطاقُ
 * العامُّ والمرشِّحُ والترتيبُ الافتراضيّ معاً.
 */
class RedemptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'redemptions';

    protected static ?string $title = 'طلبات الاستبدال';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            /*
            | بلا تقييدِ أعمدة: `users` لا تحمل عمودَ `name` — هو مُلحَقٌ فوق
            | `first_name` و`last_name` — فتحميلٌ مقيَّدٌ يُفرِّغ كلَّ اسمٍ في
            | الصفحة بلا خطأٍ واحد.
            */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['student', 'decider']))
            ->columns([
                TextColumn::make('student.first_name')->label('الطالب')
                    ->formatStateUsing(fn (Redemption $record): string => $record->student->name ?? '—'),
                TextColumn::make('coins_spent')->label('العملات المخصومة')
                    ->description('مجمَّدةٌ لحظةَ الطلب: السعرُ قد يتغيّر بعدها.'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (RedemptionStatus $state): string => $state->labelAr())
                    ->color(fn (RedemptionStatus $state): string => match ($state) {
                        RedemptionStatus::Pending => 'warning',
                        RedemptionStatus::Fulfilled => 'success',
                        RedemptionStatus::Rejected => 'danger',
                    }),
                TextColumn::make('claimed_month_key')->label('الشهر المحتسَب'),
                TextColumn::make('decider.first_name')->label('البتّ فيه')->placeholder('—')
                    ->formatStateUsing(fn (Redemption $record): ?string => $record->decider?->name),
                TextColumn::make('decided_at')->label('تاريخ البتّ')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('created_at')->label('تاريخ الطلب')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(self::statusOptions()),
            ]);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (RedemptionStatus::cases() as $case) {
            $options[$case->value] = $case->labelAr();
        }

        return $options;
    }
}
