<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Filament\Pages;

use App\Modules\Settlement\Support\SettlementAuditSubjects;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * سجلُّ تدقيقِ التسوية — كلُّ قرارٍ إداريٍّ على مالِ المدرّس، ولا شيءَ غيرُه (FR-034).
 *
 * ⛔ `GET /admin/settlement/audit` قائمٌ منذُ ٠١٤ ولا تقرؤه شاشة: لا ملفَّ في
 * `frontend/src` ينادِيه ولا صفحةَ في اللوحة. فالسجلُّ يُكتَبُ ولا يُقرَأ — وهو
 * بالضبطِ ما يقولُ عنه `tenancy.md` «صلاحيّةٌ قارئُها خلفَ مسارٍ لا ينادِيه عميلٌ
 * لا تحرسُ شيئاً». هنا بجوارِ شاشتَي الصرفِ واعتمادِ الأسعار، لأنّ هذا مكانُ عملِ
 * موظّفِ المنصّةِ في هذا السياق.
 *
 * ⚠️ الاستعلامُ ليس هنا — هو {@see SettlementAuditSubjects::entries()} نفسُه الذي
 * يقرؤه الـAPI. المرشِّحُ أدناه يضيّقُ داخلَ الأنواعِ الستّة ولا يوسّعها، وخياراتُه
 * من `MAP` لا من قائمةٍ مكتوبةٍ ثانيةً هنا.
 *
 * ⚠️ والصفحةُ للقراءةِ وحدَها. لا زرَّ على صفٍّ فيها: السجلُّ لا يُعدَّل
 * (`ActivityEntry`)، والتصحيحُ فعلٌ في شاشتِه ({@see ReverseTeachingUnits}).
 */
class SettlementAuditLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.settlement-audit-log';

    protected static ?string $slug = 'settlement-audit';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 29;

    /** Arabic for what was decided. Unmapped falls through as its slug, never as a blank. */
    public const EVENT_LABELS = [
        'settlement.unit.reversed' => 'عكس وحدة تدريس',
        'settlement.payout.executed' => 'صرف مستحقّ',
        'settlement.period.closed' => 'إغلاق فترة',
        'settlement.deduction.recorded' => 'تسجيل خصم',
        'settlement.rate.approved' => 'اعتماد سعر',
        'settlement.rate.rejected' => 'رفض طلب سعر',
    ];

    /** The slugs of {@see SettlementAuditSubjects::MAP}, in Arabic. */
    public const SUBJECT_LABELS = [
        'period' => 'فترة تسوية',
        'payout' => 'صرف',
        'unit' => 'وحدة تدريس',
        'ledger_entry' => 'قيد دفتر',
        'rate' => 'سعر',
        'rate_request' => 'طلب سعر',
    ];

    /** Property keys the settlement Actions write, in the order a reader wants them. */
    private const PROPERTY_LABELS = [
        'amount_minor' => 'المبلغ',
        'requested_amount_minor' => 'المبلغ المطلوب',
        'net_minor' => 'الصافي',
        'carried_out_minor' => 'المرحَّل',
        'units_count' => 'الحصص',
        'session_type' => 'النوع',
        'reference' => 'المرجع',
        'method' => 'الطريقة',
        'reason' => 'السبب',
    ];

    /** The API route's own permission — a platform one: super-admin and `finance-admin`. */
    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::SETTLEMENT_AUDIT_VIEW) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'سجلّ تدقيق التسوية';
    }

    public function getTitle(): string
    {
        return 'سجلّ تدقيق التسوية';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SettlementAuditSubjects::entries())
            // The query already orders by id, newest first; Filament's default
            // sort would otherwise re-order on the primary key the same way.
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('لا قرارات مسجّلة بعد')
            ->emptyStateDescription('يظهر هنا كلّ إغلاق وصرف وخصم وتصحيح واعتماد سعر فور وقوعه.')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                TextColumn::make('description')->label('القرار')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (self::EVENT_LABELS[$state] ?? $state)),
                TextColumn::make('subject_label')->label('على')
                    ->state(fn (Activity $record): string => self::subjectLabel($record))
                    ->description(fn (Activity $record): string => self::subjectUuid($record)),
                TextColumn::make('actor')->label('المنفّذ')
                    // Null when nobody did it: the nightly sweep closes periods,
                    // and a name there would put someone on a decision nobody took.
                    ->state(fn (Activity $record): string => self::actorName($record)),
                TextColumn::make('details')->label('التفاصيل')->wrap()
                    ->state(fn (Activity $record): string => self::details($record)),
            ])
            ->filters([
                SelectFilter::make('subject_type')
                    ->label('على')
                    ->options(self::subjectFilterOptions())
                    // Narrows INSIDE the six types `entries()` already asked for:
                    // the option keys are the MAP's own classes, so there is no
                    // value that could widen it.
                    ->query(fn (Builder $query, array $data): Builder => is_string($data['value'] ?? null)
                        && array_key_exists($data['value'], SettlementAuditSubjects::MAP)
                            ? $query->where('subject_type', $data['value'])
                            : $query),
                SelectFilter::make('description')
                    ->label('القرار')
                    ->options(self::EVENT_LABELS),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** @return array<string, string> class → Arabic label */
    private static function subjectFilterOptions(): array
    {
        $options = [];

        foreach (SettlementAuditSubjects::MAP as $class => $slug) {
            $options[$class] = self::SUBJECT_LABELS[$slug];
        }

        return $options;
    }

    private static function subjectLabel(Activity $record): string
    {
        $slug = SettlementAuditSubjects::slugFor($record->subject_type);

        return $slug === null ? '—' : (self::SUBJECT_LABELS[$slug] ?? $slug);
    }

    /** The uuid, never the autoincrement id — and «محذوف» when the row is gone. */
    private static function subjectUuid(Activity $record): string
    {
        $subject = $record->subject;

        return $subject instanceof Model ? (string) $subject->getAttribute('uuid') : 'العنصر محذوف';
    }

    private static function actorName(Activity $record): string
    {
        $causer = $record->causer;

        return $causer instanceof Model ? (string) $causer->getAttribute('name') : 'النظام';
    }

    /**
     * The properties the Action chose to record, labelled.
     *
     * Money keys are minor units and printed as such to two places — the entry
     * carries no currency, and guessing one here would be a figure the ledger
     * never wrote. `workspace_id` is an autoincrement id the shared trait adds to
     * every entry; it is left out, as the API resource leaves it out.
     */
    public static function details(Activity $record): string
    {
        $properties = $record->properties?->toArray() ?? [];
        $parts = [];

        foreach (self::PROPERTY_LABELS as $key => $label) {
            $value = $properties[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $label.': '.(str_ends_with($key, '_minor') && is_numeric($value)
                ? number_format(((int) $value) / 100, 2)
                : (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)));
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
