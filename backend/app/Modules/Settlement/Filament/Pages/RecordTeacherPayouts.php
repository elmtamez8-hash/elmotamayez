<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Filament\Pages;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Settlement\Actions\RecordTeacherPayout;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Support\Money;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * شاشةُ صرفِ مستحقّاتِ المدرّسين — البابُ الذي لم يكنْ له زرّ.
 *
 * ⛔ الفتراتُ تُغلَقُ وحدَها (`CloseDueSettlementPeriodsJob`) فيتجمَّدُ فيها صافي
 * المستحقّ، و`POST /admin/settlement/periods/{period}/payouts` قائمٌ منذُ ٠١٤ —
 * **ولا ينادِيه ملفٌّ في `frontend/src` ولا شاشةٌ في اللوحة**. فالمالُ يُحوَّلُ
 * يدويّاً خارجَ المنصّةِ ولا شيءَ داخلَها يقولُ إنّه حُوِّل: الفترةُ تبقى «مغلقة»
 * إلى الأبد، والمدرّسُ لا يصلُه إشعارُ الصرف، ورصيدُه في دفترِ الأستاذِ لا ينزلُ
 * إلى الصفر. عائلةُ `ReviewRateRequests` نفسُها، بجوارِها في هذا المجلَّد.
 *
 * ⚠️ الزرُّ يُنادي {@see RecordTeacherPayout} وحدَه ولا منطقَ هنا: الإغلاقُ شرطٌ،
 * والصافي الموجبُ شرطٌ، والفهرسُ الفريدُ على الفترةِ هو ما يمنعُ الصرفَ مرّتين —
 * كلُّها هناك، والـAPI يسلكُ البابَ نفسَه.
 *
 * ⚠️ وبلا نطاقِ ورشةٍ في كلِّ قراءة، ومنها التحميلُ المسبق: الصارفُ موظّفُ منصّةٍ،
 * و`WorkspaceContext::id()` ترتدُّ إلى `last_workspace_id` له كما لغيرِه، فقائمةٌ
 * منطوقةٌ تعرضُ فتراتِ ورشتِه وحدَها بلا خطأ (عيبُ ٠٢٤)، و`->with('teacherProfile')`
 * منطوقاً يُعيدُ اسمَ المدرّسِ فارغاً لكلِّ ورشةٍ أخرى.
 */
class RecordTeacherPayouts extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.record-teacher-payouts';

    protected static ?string $slug = 'record-teacher-payouts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 27;

    /**
     * الصلاحيّةُ نفسُها التي يسألُها مسارُ الـAPI عبرَ `SettlementPeriodPolicy::pay()`.
     *
     * ⚠️ `settlement.payout.execute` لا يحملُها اليومَ إلّا السوبر أدمن (عبرَ
     * `Permissions::all()`) — `finance-admin` لا يملكُها في `RolePermissionMatrix`.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::SETTLEMENT_PAYOUT_EXECUTE) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'صرف مستحقّات المدرّسين';
    }

    public function getTitle(): string
    {
        return 'صرف مستحقّات المدرّسين';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SettlementPeriod::query()
                ->withoutWorkspaceScope()
                ->where('status', SettlementPeriodStatus::Closed->value)
                ->with([
                    // ⚠️ التجاوزُ لكلِّ نموذج: الاستعلامُ الخارجيُّ وحدَه لا يُحرِّرُ
                    // التحميلَ المسبق، و`TeacherProfile` منطوقٌ بورشة.
                    'teacherProfile' => fn ($relation) => $relation
                        ->withoutGlobalScope(WorkspaceScope::class)
                        ->withTrashed(),
                    'teacherProfile.user',
                    'workspace',
                ])
                ->orderBy('ends_on'))
            ->emptyStateHeading('لا فترات مغلقة بانتظار الصرف')
            ->columns([
                TextColumn::make('teacher')->label('المدرّس')->placeholder('—')
                    ->state(fn (SettlementPeriod $record): ?string => $record->teacherProfile?->user?->name)
                    ->description(fn (SettlementPeriod $record): ?string => $record->workspace?->name),
                TextColumn::make('window')->label('الفترة')
                    ->state(fn (SettlementPeriod $record): string => $record->starts_on->format('Y-m-d')
                        .' ← '.$record->ends_on->format('Y-m-d')),
                TextColumn::make('units_count')->label('الحصص'),
                TextColumn::make('gross_minor')->label('الإجماليّ')
                    ->state(fn (SettlementPeriod $record): string => Money::format($record->gross_minor, (string) $record->currency)),
                TextColumn::make('deductions_minor')->label('الخصومات')
                    ->state(fn (SettlementPeriod $record): string => Money::format($record->deductions_minor, (string) $record->currency)),
                TextColumn::make('net_minor')->label('المستحقّ')
                    ->weight('bold')
                    ->state(fn (SettlementPeriod $record): string => $record->net_minor > 0
                        ? Money::format($record->net_minor, (string) $record->currency)
                        // FR-026 — الصافي السالبُ يُرحَّلُ ولا يُصرَف، ويُقالُ ذلك
                        // هنا لا يُترَكُ رقماً سالباً يُقرَأُ دَيناً على المدرّس.
                        : 'لا مستحقّ — يُرحَّل '.Money::format($record->net_minor, (string) $record->currency)),
                TextColumn::make('closed_at')->label('أُغلقت')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('pay')
                    ->label('سجّل الدفع')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->visible(fn (SettlementPeriod $record): bool => $record->net_minor > 0)
                    // السياسةُ نفسُها التي يسألُها مسارُ الـAPI — لا `can()` معادُ الاشتقاق.
                    ->authorize(fn (SettlementPeriod $record): bool => Gate::allows('pay', $record))
                    ->requiresConfirmation()
                    ->modalHeading('تسجيل صرف المستحقّ')
                    ->modalDescription(fn (SettlementPeriod $record): string => 'يُسجَّلُ أنّ '
                        .Money::format($record->net_minor, (string) $record->currency)
                        .' حُوِّلت إلى المدرّس، ويصلُه إشعار. لا يُتراجَع عن هذا من الشاشة — سجّله بعد إتمام التحويل فعلاً.')
                    ->schema([
                        TextInput::make('reference')
                            ->label('مرجع التحويل')
                            ->maxLength(191)
                            ->helperText('ما يطابقه المدرّس مع كشف حسابه. اتركه فارغاً إن لم يصلك بعد.'),
                        TextInput::make('method')
                            ->label('طريقة الدفع')
                            ->maxLength(32)
                            ->placeholder('تحويل بنكي'),
                    ])
                    ->action(fn (SettlementPeriod $record, array $data) => $this->pay($record, $data)),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** @param  array<string, mixed>  $data */
    private function pay(SettlementPeriod $record, array $data): void
    {
        $officer = Auth::user();

        if (! $officer instanceof User) {
            return;
        }

        /*
        | ⛔ التحقّقُ بخطوتَينِ يُسأَلُ بيدٍ: `/admin` يُصادَقُ بالجلسةِ فلا يمرُّ
        | بوسيطِ `2fa.required`، وهذا زرٌّ يُسجِّلُ خروجَ مال. الجملةُ من
        | `TwoFactorMandate` نفسِها، كما في شاشتَي الطلباتِ واعتمادِ الأسعار.
        */
        if (($refusal = TwoFactorMandate::refusalFor($officer)) !== null) {
            Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

            return;
        }

        $reference = is_string($data['reference'] ?? null) && trim($data['reference']) !== '' ? trim($data['reference']) : null;
        $method = is_string($data['method'] ?? null) && trim($data['method']) !== '' ? trim($data['method']) : null;

        try {
            $payout = app(RecordTeacherPayout::class)->handle($record, $officer, $reference, $method);
        } catch (DomainException $refused) {
            Notification::make()->danger()->title('تعذّر التسجيل')->body($refused->getMessage())->send();

            return;
        }

        if ($payout === null) {
            // جملةُ المتحكِّمِ نفسِها: ضغطتانِ أو شاشتانِ، والثانيةُ ليست عطلاً.
            Notification::make()->warning()->title('صُرفت هذه الفترة سلفاً.')->send();

            return;
        }

        Notification::make()->success()->title('سُجِّل الصرف وأُبلغ المدرّس')->send();
    }
}
