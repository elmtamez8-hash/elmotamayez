<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            /*
            | ⚠️ التخطّي هنا كما في {@see OrderResource::getEloquentQuery()}، وبلا
            | ثالثةٍ تكسرُ الاثنَين: الطلبُ يُقرأُ بصلاحيّةِ منصّةٍ عبرَ النطاق،
            | وحركاتُه لا. و`WorkspaceContext::id()` يرتدُّ إلى `last_workspace_id`
            | حتّى لموظّفِ المال، فمساحتُه هو تُقارَنُ بمساحةِ الطلب: تُطابِقُ
            | بالمصادفةِ فتظهرُ الحركات، ولا تُطابِقُ فيقرأُ «لا حركات دفع» عن طلبٍ
            | له حركة — بلا خطأ. تجهيزةٌ بمساحةٍ واحدةٍ لا ترى هذا أبداً.
            */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(WorkspaceScope::class))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('provider')->label('المزوّد')->badge(),
                TextColumn::make('amount_minor')
                    ->label('المبلغ')
                    ->money(fn (PaymentTransaction $record): string => (string) $record->currency, divideBy: 100),
                /*
                | ⛔ كان الإغلاقانِ يشترطانِ `string` — و`PaymentTransaction::$status`
                | **مصبوبٌ إلى `PaymentStatus`**، فيصلُ الحالةُ كائنَ enum لا نصّاً.
                | فيرمي PHP `TypeError`، وتسقطُ معه **الصفحةُ كلُّها**: «حدث خطأ
                | أثناء تحميل الصفحة» على شاشةِ الطلبِ الذي يُعتمَدُ منها.
                |
                | ⚠️ ولا يظهرُ إلّا على طلبٍ **له حركةُ دفعٍ واحدةٌ على الأقلّ**:
                | جدولٌ فارغٌ لا يُنفِّذُ مُنسِّقَ عمود. قِيسَ على الإنتاج: الطلبُ ٥
                | (حركةٌ واحدة) يسقط، والطلبُ ٧ (بلا حركات) يفتحُ سليماً — فبدا
                | العطلُ متقطّعاً وهو حتميّ.
                |
                | و`mixed` ثمّ التطبيعُ هو الهجاءُ نفسُه الذي يستعملُه
                | {@see UserResource} فوقَ `platform_role`. وقِيسَت الثمانيةَ عشرَ
                | ملفّاً في اللوحة: هذا العمودُ وحدَه مصبوبٌ إلى enum تحتَ إغلاقٍ
                | يشترطُ `string`.
                */
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::status($state)?->label()
                        ?? (is_scalar($state) ? (string) $state : '—'))
                    ->color(fn (mixed $state): string => match (self::status($state)) {
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

    /** الحالةُ تصلُ كائنَ enum من الصبِّ، ونصّاً من أيِّ قراءةٍ خام. */
    private static function status(mixed $state): ?PaymentStatus
    {
        if ($state instanceof PaymentStatus) {
            return $state;
        }

        return is_string($state) ? PaymentStatus::tryFrom($state) : null;
    }
}
