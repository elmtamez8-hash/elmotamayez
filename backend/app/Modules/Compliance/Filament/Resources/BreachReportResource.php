<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources;

use App\Models\User;
use App\Modules\Compliance\Actions\AdvanceBreachReport;
use App\Modules\Compliance\Enums\BreachStatus;
use App\Modules\Compliance\Filament\Resources\BreachReportResource\Pages;
use App\Modules\Compliance\Http\Resources\BreachReportResource as BreachReportPayload;
use App\Modules\Compliance\Models\BreachReport;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * بلاغاتُ تسرُّبِ البيانات: أين وصلَ التعاملُ معها، وكم بقيَ من مهلةِ الإخطار.
 *
 * ⚠️ لا نموذجَ تحريرٍ هنا، والأزرارُ على الصفِّ كلُّها تمرُّ بـ`AdvanceBreachReport`
 * وحدَه. تقدُّمُ البلاغِ فعلٌ له حرّاسُه — ومنها القاعدةُ التي لا يعرفُها نموذجُ
 * لوحة: `notified` مرفوضةٌ ما لم يوجدْ ختمَا الإخطارِ كلاهما، ولا يُعادُ ختمُ
 * أحدِهما أبداً. ختمٌ يُعادُ كتابتُه من شاشةٍ ينقلُ واقعةً قانونيّةً إلى اللحظةِ التي
 * لمسَ فيها أحدُهم الصفَّ — أي إلى داخلِ المهلةِ دائماً، مهما تأخّرَ الإخطارُ حقّاً.
 * لذلك لا تكتبُ الأزرارُ الختمَ بنفسِها: ترسلُ «أُخطِرت الجهة» كقيمةٍ منطقيّة،
 * والـAction يختمُ الساعةَ مرّةً واحدة.
 *
 * ⚠️ وقبلَ هذه الأزرار كان `PATCH /manage/compliance/breach-reports/{uuid}` بلا
 * أيِّ قارئ: لا شاشةَ في `frontend/src` ولا زرَّ هنا، فالبلاغُ يبقى «بلاغاً جديداً»
 * إلى الأبد والعدّادُ يعدُّ على التزامٍ لا يملكُ أحدٌ بابَ الوفاءِ به.
 *
 * ⚠️ والموعدانِ مشتقّان، ولا يُشتقّانِ هنا مرّةً ثانية. حمولةُ الوحدةِ نفسِها
 * ({@see BreachReportPayload}) هي الإملاءُ الوحيدُ لهما: `created_at` زائداً نافذةَ
 * الإخطارِ من `ComplianceSettings`، ويسقطُ الموعدُ متى تمَّ الإخطارُ أو أُغلقَ
 * البلاغ. إملاءان لسؤالٍ واحدٍ يفترقان عند أوّلِ يومٍ يقصِّرُ فيه منظِّمٌ المهلة.
 *
 * ⚠️ و`reporter_contact` مخفيٌّ افتراضيّاً: هو ما تركَه إنسانٌ من خارجِ المنصّةِ
 * كي نردَّ عليه، أي بياناتٌ شخصيّةٌ لشخصٍ لم يفتحْ حساباً قطّ.
 *
 * ولا `getEloquentQuery()` هنا: `BreachReport` لا يستعملُ `BelongsToWorkspace` —
 * التسرُّبُ حادثةٌ في المنصّةِ لا في مساحةِ عملٍ واحدة — فلا نطاقَ عامٌّ يُلتَفُّ
 * عليه في الجذر، ولا علاقةَ محمَّلةً مسبقاً يتكرّرُ فيها الالتفاف.
 */
class BreachReportResource extends Resource
{
    protected static ?string $model = BreachReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'الامتثال';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return 'بلاغات التسرُّب';
    }

    public static function getModelLabel(): string
    {
        return 'بلاغ تسرُّب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'بلاغات التسرُّب';
    }

    /** بابٌ صريح: افتراضُ Filament هو السماح، والقائمةُ لا تسألُ سياسةَ الصفِّ أبداً. */
    public static function canViewAny(): bool
    {
        return self::canManage();
    }

    /**
     * صلاحيّةُ `BreachReportPolicy::manage()` نفسُها، للقراءةِ وللتقدُّمِ معاً.
     *
     * ⚠️ ويُسألُ عنها كلُّ زرٍّ صراحةً: القائمةُ لا تستدعي سياسةَ الصفِّ، و`visible()`
     * هو البابُ الوحيدُ بين الزرِّ ومَن يراه — ثمّ يُسألُ مرّةً ثانيةً داخلَ الفعل،
     * لأنَّ Livewire يقبلُ استدعاءَ زرٍّ لم يُرسَمْ.
     */
    public static function canManage(): bool
    {
        return auth()->user()?->can(Permissions::COMPLIANCE_BREACHES_MANAGE) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (BreachStatus $state): string => $state->label())
                    ->color(fn (BreachStatus $state): string => match ($state) {
                        BreachStatus::Reported => 'danger',
                        BreachStatus::Triaged => 'warning',
                        BreachStatus::Contained => 'info',
                        BreachStatus::Notified => 'info',
                        BreachStatus::Closed => 'success',
                    }),

                TextColumn::make('created_at')
                    ->label('وردَ في')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                /*
                | مصدرُ البلاغِ لا صاحبُه: الطابورُ يفرزُ ويفحص، ولا يُعرِّفُ بمن
                | أبلغ. والعمودُ يُقرأُ بـ`getStateUsing` لأنَّ `formatStateUsing`
                | لا تُستدعى أصلاً على حالةٍ فارغة — وهي الحالةُ الأكثرُ وروداً
                | هنا: البلاغُ من خارجِ المنصّةِ هو سببُ وجودِ المسارِ العلنيّ.
                */
                TextColumn::make('reported_by_user_id')
                    ->label('مصدر البلاغ')
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(fn (BreachReport $record): string => $record->reported_by_user_id === null
                        ? 'من خارج المنصّة'
                        : 'من حسابٍ مسجَّل'),

                TextColumn::make('description')
                    ->label('الوصف')
                    ->limit(70)
                    ->wrap(),

                TextColumn::make('affected_subject_count')
                    ->label('عدد المعنيّين')
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('authority_notified_at')
                    ->label('أُبلغت الجهة')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم تُبلَّغ')
                    ->toggleable(),

                TextColumn::make('authority_notice_due_at')
                    ->label('مهلة إبلاغ الجهة')
                    ->getStateUsing(fn (BreachReport $record): ?string => self::derivedDeadline($record, 'authority_notice_due_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->color(fn (?string $state): string => self::deadlineColour($state)),

                TextColumn::make('subjects_notified_at')
                    ->label('أُبلغ المعنيّون')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم يُبلَّغوا')
                    ->toggleable(),

                TextColumn::make('subjects_notice_due_at')
                    ->label('مهلة إبلاغ المعنيّين')
                    ->getStateUsing(fn (BreachReport $record): ?string => self::derivedDeadline($record, 'subjects_notice_due_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->color(fn (?string $state): string => self::deadlineColour($state)),

                TextColumn::make('reporter_contact')
                    ->label('وسيلة التواصل مع المُبلِّغ')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('closed_at')
                    ->label('أُغلقَ في')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(
                    collect(BreachStatus::cases())
                        ->mapWithKeys(fn (BreachStatus $status): array => [$status->value => $status->label()])
                        ->all(),
                ),

                /*
                | التزامانِ لا التزام: إيقافُ التسرُّبِ شيءٌ وإخبارُ من تسرَّبتْ
                | بياناتُهم شيءٌ آخر. فمُرشِّحانِ منفصلان، لأنَّ المُشغِّلَ يعملُ على
                | واحدٍ منهما في كلِّ مرّة.
                */
                Filter::make('authority_notice_pending')
                    ->label('لم تُبلَّغ الجهة بعد')
                    /**
                     * @param  Builder<BreachReport>  $query
                     */
                    ->query(function (Builder $query): void {
                        $query
                            ->whereNull('authority_notified_at')
                            ->where('status', '!=', BreachStatus::Closed->value);
                    }),

                Filter::make('subjects_notice_pending')
                    ->label('لم يُبلَّغ المعنيّون بعد')
                    /**
                     * @param  Builder<BreachReport>  $query
                     */
                    ->query(function (Builder $query): void {
                        $query
                            ->whereNull('subjects_notified_at')
                            ->where('status', '!=', BreachStatus::Closed->value);
                    }),
            ])
            ->recordActions([
                self::triageAction(),
                self::scopeAction(),
                self::containAction(),
                self::recordNoticeAction('record_authority_notice', 'authority'),
                self::recordNoticeAction('record_subjects_notice', 'subjects'),
                self::markNotifiedAction(),
                self::closeAction(),
            ]);
    }

    /** «بدء الفحص»: من بلاغٍ جديدٍ إلى قيدِ الفحص، ومعه ما عُرفَ من نطاقِ الحادث. */
    public static function triageAction(): Action
    {
        return Action::make('triage')
            ->label('ابدأ الفحص')
            ->color('warning')
            ->visible(fn (BreachReport $record): bool => $record->status === BreachStatus::Reported && self::canManage())
            ->modalHeading('بدء فحص البلاغ')
            ->modalDescription('ينتقل البلاغ إلى «قيد الفحص». النطاق اختياريّ الآن، ويمكن تعديله لاحقاً من «نطاق الحادث».')
            ->fillForm(fn (BreachReport $record): array => self::scopeDefaults($record))
            ->schema(self::scopeFields())
            ->action(function (BreachReport $record, array $data): void {
                self::advance($record, BreachStatus::Triaged, self::scopeFrom($data), 'بدأ فحص البلاغ');
            });
    }

    /**
     * «نطاق الحادث»: تعديلُ الأصنافِ والعددِ دونَ تحريكِ الحالة.
     *
     * ⚠️ الحالةُ المُرسَلةُ هي الحالةُ الحاضرةُ نفسُها، و`AdvanceBreachReport` يقبلُ
     * المساواةَ عن قصد: هذه هي الحالةُ العاديّةُ أثناءَ الفحص — النطاقُ يُعرَفُ
     * تدريجاً، ولا يستحقُّ كلُّ رقمٍ جديدٍ خطوةً في المسار.
     */
    public static function scopeAction(): Action
    {
        return Action::make('scope')
            ->label('نطاق الحادث')
            ->color('gray')
            ->visible(fn (BreachReport $record): bool => $record->status !== BreachStatus::Closed && self::canManage())
            ->modalHeading('نطاق الحادث')
            ->fillForm(fn (BreachReport $record): array => self::scopeDefaults($record))
            ->schema(self::scopeFields())
            ->action(function (BreachReport $record, array $data): void {
                self::advance($record, $record->status, self::scopeFrom($data), 'حُفظ نطاق الحادث');
            });
    }

    /** «احتُوي»: أُوقفَ التسرُّب. التزامٌ غيرُ الإخطار، ولا يُغني عنه. */
    public static function containAction(): Action
    {
        return Action::make('contain')
            ->label('تمّ الاحتواء')
            ->color('info')
            ->visible(fn (BreachReport $record): bool => $record->status->rank() < BreachStatus::Contained->rank() && self::canManage())
            ->requiresConfirmation()
            ->modalHeading('تسجيل احتواء التسرُّب')
            ->modalDescription('يعني أنّ التسرُّب أُوقف. لا يُغني عن إخطار الجهة المختصّة ولا المعنيّين، ولا رجوع إلى حالةٍ سابقة بعده.')
            ->action(function (BreachReport $record): void {
                self::advance($record, BreachStatus::Contained, [], 'سُجِّل احتواء التسرُّب');
            });
    }

    /**
     * تسجيلُ أحدِ الإخطارَين — الجهةِ المختصّةِ أو المعنيّين — دونَ تحريكِ الحالة.
     *
     * ⚠️ الزرُّ يختفي متى وُجدَ الختم، لأنَّ الختمَ لا يُعادُ أبداً: زرٌّ ثانٍ على
     * صفٍّ مختومٍ يقولُ للمُشغِّلِ إنَّ الضغطَ يُحدِّثُ الموعدَ، والـAction لن يفعل.
     *
     * @param  'authority'|'subjects'  $side
     */
    public static function recordNoticeAction(string $name, string $side): Action
    {
        $column = $side === 'authority' ? 'authority_notified_at' : 'subjects_notified_at';
        $label = $side === 'authority' ? 'أُخطِرت الجهة المختصّة' : 'أُخطِر المعنيّون';

        return Action::make($name)
            ->label($label)
            ->color('info')
            ->visible(fn (BreachReport $record): bool => $record->{$column} === null
                && $record->status !== BreachStatus::Closed
                && self::canManage())
            ->requiresConfirmation()
            ->modalHeading($label)
            ->modalDescription('يُختَم الآن بتاريخ هذه اللحظة، مرّةً واحدةً لا تُعدَّل بعدها — فاضغط بعد أن يتمّ الإخطار فعلاً، لا قبله.')
            ->action(function (BreachReport $record) use ($side, $label): void {
                self::advance($record, $record->status, [$side.'_notified' => true], 'سُجِّل: '.$label);
            });
    }

    /**
     * «أُبلغت الجهاتُ والمعنيّون»: الحالةُ التي تُسقطُ العدّادَين.
     *
     * ⚠️ ظاهرٌ ولو لم يُختَمْ أيٌّ من الإخطارَين، عن قصد. الرفضُ يُقالُ ولا يُخفى:
     * زرٌّ يختفي يُقرأُ شاشةً معطوبة، أمّا الرفضُ فيسمّي ما ينقصُ — وهو من
     * `AdvanceBreachReport` نفسِه، فلا جملةَ ثانيةً هنا تفترقُ عن جملةِ الـAPI.
     * والخانتان في النافذةِ تختمان ما لم يُختَمْ بعدُ في الضغطةِ نفسِها.
     */
    public static function markNotifiedAction(): Action
    {
        return Action::make('mark_notified')
            ->label('اكتمل الإخطار')
            ->color('success')
            ->visible(fn (BreachReport $record): bool => $record->status->rank() < BreachStatus::Notified->rank() && self::canManage())
            ->modalHeading('اعتماد اكتمال الإخطار')
            ->modalDescription('لا يُعتمَد ما لم يُسجَّل إخطار الجهة المختصّة وإخطار المعنيّين كلاهما.')
            ->schema([
                Checkbox::make('authority_notified')
                    ->label('أُخطِرت الجهة المختصّة (يُختَم الآن)')
                    ->visible(fn (BreachReport $record): bool => $record->authority_notified_at === null),
                Checkbox::make('subjects_notified')
                    ->label('أُخطِر المعنيّون (يُختَم الآن)')
                    ->visible(fn (BreachReport $record): bool => $record->subjects_notified_at === null),
            ])
            ->action(function (BreachReport $record, array $data): void {
                $triage = [];

                if (($data['authority_notified'] ?? false) === true) {
                    $triage['authority_notified'] = true;
                }

                if (($data['subjects_notified'] ?? false) === true) {
                    $triage['subjects_notified'] = true;
                }

                self::advance($record, BreachStatus::Notified, $triage, 'اعتُمد اكتمال الإخطار');
            });
    }

    /** «إغلاق»: نهايةُ المسار، ولا رجوعَ منها. */
    public static function closeAction(): Action
    {
        return Action::make('close')
            ->label('أغلق البلاغ')
            ->color('danger')
            ->visible(fn (BreachReport $record): bool => $record->status !== BreachStatus::Closed && self::canManage())
            ->requiresConfirmation()
            ->modalHeading('إغلاق البلاغ')
            ->modalDescription('يخرج البلاغ من الطابور ويسقط عدّاد المهلة، ولا يُعاد فتحه. تأكّد أنّ ما يلزم من إخطارٍ قد تمّ أو أنّه لا يلزم.')
            ->modalSubmitActionLabel('أغلق')
            ->action(function (BreachReport $record): void {
                self::advance($record, BreachStatus::Closed, [], 'أُغلق البلاغ');
            });
    }

    /**
     * البابُ الوحيدُ من هذه الشاشةِ إلى `AdvanceBreachReport`.
     *
     * ⚠️ `refresh()` عند الرفضِ ليس زينة: الـAction يملأُ ختمَي الإخطارِ في الذاكرةِ
     * قبلَ أن يرفضَ `notified` ثمَّ يرمي قبلَ الحفظ — فالقاعدةُ سليمة، لكنَّ الصفَّ
     * الذي بيدِ Livewire يبقى يحملُ ختماً لم يُكتَبْ، والشاشةُ تعرضُه كأنّه كُتب.
     *
     * ⚠️ والتحقّقُ بخطوتين يُسألُ هنا كما في `OrderResource`: اللوحةُ لا تمرُّ بـ
     * `2fa.required`، والمسارُ في الـAPI يحملُه — والجملةُ جملةُ `TwoFactorMandate`.
     *
     * @param  array{affected_categories?: list<string>|null, affected_subject_count?: int|null, authority_notified?: bool, subjects_notified?: bool}  $triage
     */
    private static function advance(BreachReport $record, BreachStatus $to, array $triage, string $done): void
    {
        abort_unless(self::canManage(), 403);

        $officer = auth()->user();
        abort_unless($officer instanceof User, 403);

        if (($refusal = TwoFactorMandate::refusalFor($officer)) !== null) {
            Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

            return;
        }

        try {
            app(AdvanceBreachReport::class)->handle($record, $to, $triage);
        } catch (DomainException $refused) {
            $record->refresh();

            Notification::make()->danger()->title($refused->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($done)->send();
    }

    /** @return list<Select|TextInput> */
    private static function scopeFields(): array
    {
        return [
            /*
            | من سجلِّ الأصنافِ نفسِه، لا نصّاً حرّاً: الأصنافُ هي ما تعلنُه صفحةُ
            | السياسة، وتسرُّبٌ يُسجَّلُ بأسماءٍ لا يعرفُها السجلُّ لا يُطابَقُ بما
            | وعدْنا بحفظِه.
            */
            Select::make('affected_categories')
                ->label('الأصناف المتأثّرة')
                ->multiple()
                ->options(fn (): array => DataCategory::query()->orderBy('label')->pluck('label', 'key')->all()),

            TextInput::make('affected_subject_count')
                ->label('عدد المتأثّرين')
                ->helperText('تقديرٌ يُحدَّث كلّما عُرف أكثر. اتركه فارغاً إن لم يُعرف بعد.')
                ->numeric()
                ->integer()
                ->minValue(0),
        ];
    }

    /** @return array{affected_categories: list<string>, affected_subject_count: int|null} */
    private static function scopeDefaults(BreachReport $record): array
    {
        return [
            'affected_categories' => $record->affected_categories ?? [],
            'affected_subject_count' => $record->affected_subject_count,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{affected_categories: list<string>|null, affected_subject_count: int|null}
     */
    private static function scopeFrom(array $data): array
    {
        $categories = $data['affected_categories'] ?? null;
        $count = $data['affected_subject_count'] ?? null;

        return [
            'affected_categories' => is_array($categories) && $categories !== []
                ? array_values(array_map(strval(...), $categories))
                : null,
            'affected_subject_count' => is_numeric($count) ? (int) $count : null,
        ];
    }

    /**
     * أحدُ الموعدين، مقروءاً من حمولةِ الوحدةِ بدلَ اشتقاقِه من جديد.
     *
     * لا استعلامَ فيه: البناءُ يقرأُ أعمدةَ الصفِّ الحاضرِ وحدَها، فتكرارُه لكلِّ
     * صفٍّ لا يصنعُ N+1.
     */
    private static function derivedDeadline(BreachReport $record, string $key): ?string
    {
        $payload = (new BreachReportPayload($record))->toArray(request());
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** الموعدُ الذي مضى يُقرأُ أحمر؛ والموعدُ الساقطُ لا لونَ له. */
    private static function deadlineColour(?string $state): string
    {
        return $state !== null && CarbonImmutable::parse($state)->isPast() ? 'danger' : 'gray';
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBreachReports::route('/'),
        ];
    }
}
