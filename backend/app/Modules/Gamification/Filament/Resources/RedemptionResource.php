<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources;

use App\Models\User;
use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Gamification\Filament\Resources\RedemptionResource\Pages;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * طابورُ طلباتِ الاستبدالِ عبر كلِّ المكافآت — للقراءة فقط.
 *
 * ⚠️ البتُّ لا يمرُّ من هنا. `DecideRedemption` ينقل الحالةَ بجملةِ UPDATE شرطيّةٍ
 * واحدة، لأنّ ضغطتَي «رفض» تقرآن كلتاهما `pending` فتُعيدان العملاتِ مرّتين —
 * عملاتٌ من العدم، وهي الجهةُ التي لا يحرسها FR-034 لكونه مكتوباً عن الرصيدِ حين
 * يهبط تحت الصفر. زرُّ تعديلٍ من هذه الشاشةِ يكتب `status` مباشرةً ويتخطّى ذلك
 * كلَّه، ويتخطّى معه إعادةَ المخزونِ وعدّادَ الشهرِ المحفوظَ في `claimed_month_key`.
 *
 * ⚠️ والبابُ مُعلَنٌ هنا لا متروكاً للسياسة: قائمةُ Filament لا تستدعي سياسةَ
 * الصفِّ أبداً، و`viewAny` الافتراضيّةُ تسمح — فمَورِدٌ بلا رفضٍ صريحٍ هو مَورِدٌ
 * بلا حارس.
 */
class RedemptionResource extends Resource
{
    protected static ?string $model = Redemption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'التلعيب';

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return 'طلبات الاستبدال';
    }

    public static function getModelLabel(): string
    {
        return 'طلب استبدال';
    }

    public static function getPluralModelLabel(): string
    {
        return 'طلبات الاستبدال';
    }

    /**
     * الطالبُ والمكافأةُ ومَن بتَّ في الطلب، محمَّلون مع الصفوف لا صفّاً صفّاً.
     *
     * ⚠️ وبلا تقييدِ أعمدة: `users` لا تحمل عمودَ `name` — هو مُلحَقٌ فوق
     * `first_name` و`last_name` — فتحميلٌ مقيَّدٌ يُسقطهما ويُفرِّغ كلَّ اسمٍ في
     * الصفحة بردٍّ ٢٠٠ وبلا خطأٍ واحد.
     *
     * ⚠️ `Builder<Model>` لا `Builder<Redemption>`. `Resource` عامٌّ على `TModel`
     * وافتراضُه `Model`، و`parent::getEloquentQuery()` تُرجِعُ ذلك النوعَ نفسَه —
     * فوسمٌ بالنموذجِ الملموسِ هنا يَعِدُ بما لا يُرجِعُه الجسدُ فعلاً.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student', 'reward', 'decider']);
    }

    public static function table(Table $table): Table
    {
        return $table
            // الترتيبُ والمرشِّحُ معاً يركبان `(workspace_id, status, created_at)`،
            // وهو الفهرسُ الوحيدُ الذي يخدم هذه الشاشة.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('student.first_name')->label('الطالب')
                    ->formatStateUsing(fn (Redemption $record): string => $record->student->name ?? '—')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('reward.title')->label('المكافأة')->searchable(),
                TextColumn::make('coins_spent')->label('العملات المخصومة')->sortable()
                    ->description('مجمَّدةٌ لحظةَ الطلب: السعرُ قد يتغيّر بعدها.'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (RedemptionStatus $state): string => $state->labelAr())
                    ->color(fn (RedemptionStatus $state): string => match ($state) {
                        RedemptionStatus::Pending => 'warning',
                        RedemptionStatus::Fulfilled => 'success',
                        RedemptionStatus::Rejected => 'danger',
                    }),
                TextColumn::make('claimed_month_key')->label('الشهر المحتسَب')
                    ->tooltip('الشهرُ الذي استُهلك عدّادُه، لا شهرُ البتّ: بدونه يضيع المخزونُ عند رفضٍ بعد انقلاب الشهر.'),
                TextColumn::make('decider.first_name')->label('البتّ فيه')->placeholder('—')
                    ->formatStateUsing(fn (Redemption $record): ?string => $record->decider?->name),
                TextColumn::make('decided_at')->label('تاريخ البتّ')->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
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

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRedemptions::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(Permissions::REDEMPTIONS_FULFILL);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
