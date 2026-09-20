<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Filament\Pages;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Settlement\Actions\DecideRateChange;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

/**
 * شاشةُ اعتمادِ سعرِ المدرّس — البابُ الذي لم يكنْ له زرّ.
 *
 * ⛔ الطلبُ كانَ يُرسَلُ ولا يُقرَأُ. `POST /settlement/rate-requests` قائمٌ منذُ ٠١٤،
 * و٠٢٧ · T083 بنى للمدرّسِ استمارةَ الطلبِ ورفعَ عنه جملةَ «تواصل مع إدارة المنصّة»؛
 * أمّا `‎/admin/settlement/rate-requests/{id}/approve` فقائمٌ منذُ ٠١٤ أيضاً
 * **ولا ينادِيه ملفٌّ واحدٌ في `frontend/src` ولا شاشةٌ في اللوحة** — قِيسَ
 * ٢٠٢٦-٠٩-٢١. فالمدرّسُ يطلبُ ولا أحدَ يستطيعُ أن يُقرِّر، من أيِّ سطحٍ كان.
 *
 * ⚠️ وهي عائلةُ `writeBans.lift` المسجَّلةُ في `CLAUDE.md`: إجراءٌ ونقطةُ نهايةٍ
 * وسياسةٌ وحدٌّ للنداء — ولا شاشة. **والنصفُ الأوّلُ منها شُحِنَ في ٠٢٧**، فصارَ
 * البابُ مفتوحاً بلا مخرج.
 *
 * ⚠️ **ولم تكنْ قد عضَّت بعد**: `rate_change_requests` على الإنتاج صفرُ صفوفٍ يومَ
 * كُتِبَت هذه الشاشة. أوّلُ مدرّسٍ يطلبُ كانَ طلبُه سيقف.
 *
 * ٠٠٦ · T097 — والصياغةُ الأصليّةُ كانت غيرَ قابلةٍ للتنفيذ: حقلٌ في حمولةِ اعتمادِ
 * السعرِ يُحتسَبُ من `credit_purchases` هو حمولةُ تسويةٍ تقرأُ جداولَ الفوترة، وهو
 * بعينِه ما يُفشِلُ `ContextIsolationTest`. فالعدّادُ يُقرَأُ هنا **بجوارِ** القرارِ
 * لا داخلَ حمولتِه: سياقانِ وقراءتانِ، بلا مفتاحٍ بينَهما.
 */
class ReviewRateRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.review-rate-requests';

    protected static ?string $slug = 'review-rate-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 26;

    /**
     * ⚠️ الحارسُ مكرَّرٌ هنا ولا يُورَثُ من القائمة.
     *
     * قائمةٌ مُرشَّحةٌ تُشكِّلُ طلباً واحداً ولا تُشكِّلُ الذي يليه — والمسارُ يُكتَبُ
     * كما يُنقَر. و`Gate::before` يُمرِّرُ السوبر أدمن فوقَ كلِّ سياسة، وهو صحيحٌ
     * وهو أيضاً سببُ سؤالِ الصلاحيّةِ على الصفحةِ نفسِها لا افتراضِها من دور.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::SETTLEMENT_RATE_APPROVE) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'اعتماد أسعار المدرّسين';
    }

    public function getTitle(): string
    {
        return 'اعتماد أسعار المدرّسين';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => RateChangeRequest::query()
                /*
                | ⛔ بلا نطاقِ ورشةٍ — وهذا هو العطبُ الخماسيُّ الذي وجدَه ٠٢٤.
                |
                | `WorkspaceContext::id()` ترتدُّ إلى `users.last_workspace_id` لكلِّ
                | مستخدِمٍ بمن فيهم موظّفُ المنصّة، فطابورٌ يُترَكُ منطوقاً يعرضُ
                | طلباتِ ورشةِ الموظّفِ وحدَها — **بلا خطأٍ ولا رسالة**، ويُقرَأُ
                | أسبوعاً هادئاً. وهو أخطرُ أشكالِ ذلك العطبِ لأنّه الوحيدُ الذي لا
                | يُصدِرُ صوتاً.
                */
                ->withoutWorkspaceScope()
                ->where('status', RateRequestStatus::Pending->value)
                ->with(['teacherProfile.user', 'workspace'])
                ->latest('requested_at'))
            ->emptyStateHeading('لا طلبات أسعار معلَّقة')
            ->columns([
                TextColumn::make('teacher')->label('المدرّس')->placeholder('—')
                    ->state(fn (RateChangeRequest $record): ?string => $record->teacherProfile?->user?->name)
                    ->description(fn (RateChangeRequest $record): ?string => $record->workspace?->name),
                TextColumn::make('session_type')->label('نوع الحصّة')->placeholder('—'),
                TextColumn::make('scope')->label('النطاق')->placeholder('كل المواد')
                    ->state(fn (RateChangeRequest $record): ?string => trim(implode(' · ', array_filter([
                        Subject::query()->whereKey($record->subject_id)->value('name'),
                        $record->grade_level,
                    ]))) ?: null),
                TextColumn::make('current_amount_minor')->label('السعر الحاليّ')
                    ->state(fn (RateChangeRequest $record): string => self::money($record->current_amount_minor, $record->currency)),
                TextColumn::make('requested_amount_minor')->label('المطلوب')
                    ->state(fn (RateChangeRequest $record): string => self::money($record->requested_amount_minor, $record->currency)),
                /*
                | ⛔ العدّادُ هو ما يجعلُ القرارَ قابلاً لأن يُتَّخَذ. السعرُ الجديدُ
                | تُسوَّى به حصصٌ **مدفوعةٌ سلفاً**، فموظّفٌ يرى الرقمَينِ وحدَهما
                | يقرّرُ وهو لا يعرفُ على كم حصّةٍ سيسري قرارُه.
                */
                TextColumn::make('outstanding')->label('حصص مدفوعة لم تُستهلَك')
                    ->state(fn (RateChangeRequest $record): string => self::outstandingFor((int) $record->workspace_id)),
                TextColumn::make('requested_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('اعتمد')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('اعتماد السعر')
                    ->modalDescription('الاعتماد يكتب سعراً سارياً، ولا يُتراجَع عنه إلّا بتصحيحٍ إداريٍّ له كاتبٌ وسبب.')
                    ->action(fn (RateChangeRequest $record) => $this->decide($record, true, null)),
                Action::make('reject')
                    ->label('ارفض')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->schema([
                        /*
                        | ⚠️ السببُ مطلوبٌ في الفعلِ نفسِه (`DecideRateChange:81`)،
                        | فحقلٌ اختياريٌّ هنا يُنتِجُ رفضاً يسقطُ عندَ الإرسالِ بجملةٍ
                        | يقرؤُها الموظّفُ عطباً في الشاشة. والمدرّسُ يقرأُ هذا السببَ.
                        */
                        Textarea::make('reason')->label('سبب الرفض')->required()->rows(3),
                    ])
                    ->action(fn (RateChangeRequest $record, array $data) => $this->decide(
                        $record,
                        false,
                        (string) ($data['reason'] ?? ''),
                    )),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function decide(RateChangeRequest $record, bool $approve, ?string $reason): void
    {
        $officer = Auth::user();

        if (! $officer instanceof User) {
            return;
        }

        /*
        | ⛔ التحقّقُ بخطوتَينِ يُسأَلُ هنا بِيَدٍ، ولا يصلُ من الوسيط.
        |
        | مساراتُ الـAPI لهذا القرارِ تحملُ `2fa.required`، و`/admin` يُصادَقُ
        | بالجلسةِ فلا يمرُّ بذلك الوسيطِ إطلاقاً. وهذا قرارٌ يكتبُ راتبَ مدرّسٍ
        | ساري المفعول. والجملةُ من `TwoFactorMandate` نفسِها، فترفُضُ اللوحةُ
        | والـAPI العمليّةَ الواحدةَ بالكلماتِ الواحدة — وهو ما فعلَته شاشةُ
        | الاشتراكاتِ للسببِ عينِه.
        */
        if (($refusal = TwoFactorMandate::refusalFor($officer)) !== null) {
            Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

            return;
        }

        try {
            $action = app(DecideRateChange::class);

            $approve
                ? $action->approve($record, $officer)
                : $action->reject($record, $officer, (string) $reason);
        } catch (DomainException $refused) {
            // جملةُ الفعلِ نفسِها: «هذا الطلب مقرَّر سلفاً» تُقرَأُ تفسيراً، وجملةٌ
            // عامّةٌ مكانَها تُقرَأُ عطباً.
            Notification::make()->danger()->title('تعذّر التنفيذ')->body($refused->getMessage())->send();

            return;
        } catch (Throwable) {
            Notification::make()->danger()->title('تعذّر التنفيذ')->body('حدث خطأ غير متوقَّع.')->send();

            return;
        }

        Notification::make()->success()->title($approve ? 'اعتُمد السعر' : 'رُفض الطلب')->send();
    }

    private static function money(?int $minor, ?string $currency): string
    {
        return $minor === null
            ? '—'
            : number_format($minor / 100, 2).' '.($currency ?? 'QAR');
    }

    /**
     * الحصصُ المدفوعةُ التي لم تُستهلَكْ بعدُ في هذه الورشة.
     *
     * ⚠️ `withoutWorkspaceScope()` على الاثنَين: الورشةُ المسؤولُ عنها هي ورشةُ
     * الطلب، لا التي يصادفُ أنّ الموظَّفَ داخلٌ إليها — وهي القراءةُ نفسُها التي
     * يكتبُها `OutstandingCreditsController`، فلا رقمانِ لسؤالٍ واحد.
     */
    private static function outstandingFor(int $workspaceId): string
    {
        $outstanding = (int) CreditBalance::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('remaining_credits', '>', 0)
            ->sum('remaining_credits');

        $sold = (int) CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->sum('credits');

        return $outstanding.' من '.$sold;
    }
}
