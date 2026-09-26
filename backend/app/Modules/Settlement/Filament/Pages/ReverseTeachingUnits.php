<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Filament\Pages;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Settlement\Actions\ReverseTeachingUnit;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\Money;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * تصحيحُ وحدةِ تدريسٍ يدويّاً — البابُ الذي لم يكنْ له زرّ.
 *
 * ⛔ `POST /admin/settlement/units/{unit}/reverse` قائمٌ منذُ ٠١٤، ولا ينادِيه
 * ملفٌّ في `frontend/src` ولا شاشةٌ في اللوحة. فالعكسُ لا يحدثُ اليومَ إلّا بمستمعِ
 * الإلغاءِ وبأمرِ `settlement:repair-unledgered-units` — ووحدةٌ نشأت خطأً، أو نزاعٌ
 * حُسِمَ لصالحِ الطالب، لا يصحّحُها موظّفٌ إلّا بطلبٍ يكتبُه بيده. عائلةُ
 * {@see RecordTeacherPayouts} نفسُها، بجوارِها.
 *
 * ⚠️ الزرُّ يُنادي {@see ReverseTeachingUnit} وحدَه ولا منطقَ هنا: رفضُ عكسِ العكس،
 * ورفضُ الضغطةِ الثانية (بمفتاحِ الوحدةِ الأصليّة، ثمّ الفهرسُ الفريدُ للسباق) —
 * كلُّها هناك، والـAPI يسلكُ البابَ نفسَه. والسببُ إلزاميٌّ بقيودِ `ReverseUnitRequest`.
 *
 * ⚠️ وبلا نطاقِ ورشةٍ في كلِّ قراءة، ومنها كلُّ تحميلٍ مسبقٍ و`withExists`: الموظّفُ
 * يرتدُّ سياقُه إلى `last_workspace_id` له، فقائمةٌ منطوقةٌ تعرضُ وحداتِ ورشتِه
 * وحدَها بلا خطأ (عيبُ ٠٢٤)، و`reversals` منطوقاً يقولُ «لم تُصحَّح» عن وحدةٍ
 * صُحِّحت فيُعرَضُ الزرُّ من جديد.
 *
 * ⚠️ `canAccess()` لا يكفي وحدَه: قائمةُ Filament لا تسألُ سياسةَ الصفّ، فالزرُّ يسألُ
 * `TeachingUnitPolicy::reverse()` بنفسِه — السياسةُ التي يسألُها مسارُ الـAPI.
 */
class ReverseTeachingUnits extends Page implements HasTable
{
    use InteractsWithTable;

    /** The controller's own sentence for a second press. */
    public const ALREADY_REVERSED = 'هذه الوحدة مصحَّحة سلفاً.';

    protected string $view = 'filament.pages.reverse-teaching-units';

    protected static ?string $slug = 'teaching-units';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 28;

    /**
     * `settlement.period.manage` — what `TeachingUnitPolicy::reverse()` asks.
     *
     * يحملُها السوبر أدمن وموظّفُ المالية `finance-admin` (قرارُ المالك ٢٠٢٦-٠٩-٢٦،
     * والترحيل `2026_09_26_000500`) — ولا دورَ ورشةٍ يحملُها: هي صلاحيّةُ منصّة.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::SETTLEMENT_PERIOD_MANAGE) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'تصحيح وحدات التدريس';
    }

    public function getTitle(): string
    {
        return 'تصحيح وحدات التدريس';
    }

    public function table(Table $table): Table
    {
        $unscoped = fn ($relation) => $relation->withoutGlobalScope(WorkspaceScope::class);

        return $table
            ->query(fn (): Builder => TeachingUnit::query()
                ->withoutWorkspaceScope()
                ->with([
                    'teacherProfile' => fn ($relation) => $relation
                        ->withoutGlobalScope(WorkspaceScope::class)
                        ->withTrashed(),
                    'teacherProfile.user',
                    'student',
                    'workspace',
                ])
                ->withExists(['reversals as has_reversal' => $unscoped]))
            ->defaultSort('delivered_at', 'desc')
            ->emptyStateHeading('لا وحدات تدريس')
            ->columns([
                TextColumn::make('teacher')->label('المدرّس')->placeholder('—')
                    ->state(fn (TeachingUnit $record): ?string => $record->teacherProfile?->user?->name)
                    ->description(fn (TeachingUnit $record): ?string => $record->workspace?->name),
                TextColumn::make('student_name')->label('الطالب')->placeholder('—')
                    ->state(fn (TeachingUnit $record): ?string => $record->student?->name),
                TextColumn::make('delivered_at')->label('الحصّة')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('amount_minor')->label('المبلغ')
                    ->state(fn (TeachingUnit $record): string => Money::format($record->amount_minor, (string) $record->currency)),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->state(fn (TeachingUnit $record): string => self::statusLabel($record)),
                TextColumn::make('reversal_reason')->label('سبب التصحيح')->placeholder('—')->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(collect(TeachingUnitStatus::cases())
                        ->mapWithKeys(fn (TeachingUnitStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                TernaryFilter::make('corrections')
                    ->label('التصحيحات')
                    ->placeholder('الكلّ')
                    ->trueLabel('الوحدات العكسية وحدها')
                    ->falseLabel('الوحدات الأصلية وحدها')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('reversal_of_id', '!=', TeachingUnit::NOT_A_REVERSAL),
                        false: fn (Builder $query): Builder => $query->where('reversal_of_id', TeachingUnit::NOT_A_REVERSAL),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->label('صحّح الوحدة')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    // A correction is never corrected, and an original is
                    // corrected once. The Action refuses both anyway; hiding the
                    // button is what keeps a second press from being offered.
                    ->visible(fn (TeachingUnit $record): bool => ! $record->isReversal()
                        && ! (bool) $record->getAttribute('has_reversal'))
                    // السياسةُ نفسُها التي يسألُها مسارُ الـAPI — لا `can()` معادُ الاشتقاق.
                    ->authorize(fn (TeachingUnit $record): bool => Gate::allows('reverse', $record))
                    ->requiresConfirmation()
                    ->modalHeading('تصحيح وحدة تدريس')
                    ->modalDescription(fn (TeachingUnit $record): string => 'تُكتَبُ وحدةٌ عكسيةٌ بمبلغ '
                        .Money::format(-$record->amount_minor, (string) $record->currency)
                        .' في دفتر المدرّس، وتبقى الوحدةُ الأصليةُ كما هي. لا يُتراجَع عن هذا من الشاشة.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('سبب التصحيح')
                            // `ReverseUnitRequest`'s own limits: a reversal with no
                            // reason is the row somebody is asked to explain later.
                            ->required()
                            ->minLength(3)
                            ->maxLength(500)
                            ->rows(3),
                    ])
                    ->action(fn (TeachingUnit $record, array $data) => $this->reverse($record, $data)),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private static function statusLabel(TeachingUnit $record): string
    {
        if (! $record->isReversal() && (bool) $record->getAttribute('has_reversal')) {
            return $record->status->label().' · مصحَّحة';
        }

        return $record->status->label();
    }

    /** @param  array<string, mixed>  $data */
    private function reverse(TeachingUnit $record, array $data): void
    {
        $officer = Auth::user();

        if (! $officer instanceof User) {
            return;
        }

        /*
        | ⛔ التحقّقُ بخطوتَينِ يُسأَلُ بيدٍ: `/admin` يُصادَقُ بالجلسةِ فلا يمرُّ
        | بوسيطِ `2fa.required`، وهذا زرٌّ يُنقِصُ مالَ مدرّس. الجملةُ من
        | `TwoFactorMandate` نفسِها، كما في شاشةِ الصرفِ بجوارِها.
        */
        if (($refusal = TwoFactorMandate::refusalFor($officer)) !== null) {
            Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

            return;
        }

        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';

        if (mb_strlen($reason) < 3) {
            Notification::make()->danger()->title('اكتب سبب التصحيح')->send();

            return;
        }

        $reversal = app(ReverseTeachingUnit::class)->handle($record, $reason, $officer);

        if ($reversal === null) {
            // ضغطتانِ أو شاشتانِ، والثانيةُ ليست عطلاً.
            Notification::make()->warning()->title(self::ALREADY_REVERSED)->send();

            return;
        }

        Notification::make()->success()->title('صُحِّحت الوحدة وسُجِّل القيد العكسي')->send();
    }
}
