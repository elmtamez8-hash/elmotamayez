<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Pages;

use App\Models\User;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Tenancy\Support\PlatformSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * ⛔ **ستّةَ عشرَ رقماً تُدارُ بها كلُّ حصّةٍ حيّةٍ على المنصّة — ولم تكنْ لواحدٍ
 * منها شاشة.**
 *
 * `config/sessions.php` يفتحُ بجملةٍ صريحة: «حدٌّ لا يتغيّرُ إلّا بشحنِ كود هو حدٌّ
 * لا يضبطُه أحد»، و‏FR-021أ يمنعُ تثبيتَ سلّمِ الحضورِ في الشيفرةِ أصلاً. ومع ذلك
 * كانت الطريقةُ الوحيدةُ لتحريكِ أيٍّ منها **تحريرَ صفٍّ في قاعدةِ الإنتاجِ
 * يدويّاً** — وهو أسوأُ من النشرِ لا أفضل: لا يُسجِّلُ من فعلَه، ولا يمرُّ بمدىً
 * مسموح، ولا يُبطِلُ الذاكرةَ المخبّأة.
 *
 * ⚠️ **وأربعةٌ منها لم تكنْ في `PlatformSettings::KEYS` أصلاً** —
 * `ticket_ttl_minutes` و`max_participants` وتنبيها فشلِ التسجيل. كانت تُقرَأُ
 * صحيحةً لأنّ `SessionSettings` يُمرِّرُ ارتدادَها صراحةً، لكنّ مفتاحاً خارجَ
 * الخريطةِ غائبٌ عن `all()` وعن `flush()`: لا تراه اللوحةُ ولا يُبطِلُه مسحُ
 * الذاكرة. نفسُ العطلِ الذي وثّقَه صفّا المتجرِ ثمّ صفّا الفوترةِ من بعدِهما.
 *
 * ⚠️ **والحقولُ تُعلَنُ مرّةً واحدةً في {@see fields()}**، ويقرأُ منها `save()`.
 * ستّةَ عشرَ سطرَ `set()` مكتوبةً بيدٍ هي ستّةَ عشرَ فرصةً لهجاءٍ ثانٍ يكتبُ صفّاً
 * لا يقرؤه أحد — وهو بالضبطِ ما حدثَ لمفاتيحِ الفوترة. والاختبارُ يمشي هذه
 * القائمةَ ويُسقِطُ البناءَ على أيِّ مفتاحٍ فيها ليس في `KEYS`.
 *
 * صلاحيّةُ الوصولِ `is_super_admin` لا صلاحيّةَ مساحة، كجارتِها
 * {@see ManagePlatformSettings}: هذه أرقامُ المنصّةِ كلِّها، ومدرّسٌ واحدٌ لا
 * يقرِّرُ متى يُفتَحُ بابُ الغرفةِ لبقيّةِ المنصّة.
 *
 * @property-read Schema $form
 */
class ManageSessionSettings extends Page
{
    protected string $view = 'filament.pages.manage-session-settings';

    protected static ?string $slug = 'session-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 11;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * The form field => the `platform_settings` key it writes, and how to cast it.
     *
     * ⚠️ ONE DECLARATION, NOT SIXTEEN HAND-WRITTEN `set()` LINES. A key spelled one
     * way on the way in and another on the way out is a screen showing a number the
     * product does not use — silently, because both spellings resolve to a default.
     * That is exactly how the two billing keys ended up written by a panel and
     * absent from the map they are listed in.
     *
     * @return array<string, array{key: string, cast: 'int'|'float'|'string'}>
     */
    public static function fields(): array
    {
        return [
            'timezone' => ['key' => 'sessions.timezone', 'cast' => 'string'],
            'grace_minutes' => ['key' => 'sessions.grace_minutes', 'cast' => 'int'],
            'absence_threshold_ratio' => ['key' => 'sessions.absence_threshold_ratio', 'cast' => 'float'],
            'required_stay_ratio' => ['key' => 'sessions.required_stay_ratio', 'cast' => 'float'],
            'teacher_required_stay_ratio' => ['key' => 'sessions.teacher_required_stay_ratio', 'cast' => 'float'],
            'attendance_edit_window_hours' => ['key' => 'sessions.attendance_edit_window_hours', 'cast' => 'int'],
            'join_window_minutes' => ['key' => 'sessions.join_window_minutes', 'cast' => 'int'],
            'ticket_ttl_minutes' => ['key' => 'sessions.ticket_ttl_minutes', 'cast' => 'int'],
            'presence_interval_seconds' => ['key' => 'sessions.presence_interval_seconds', 'cast' => 'int'],
            'max_participants' => ['key' => 'sessions.max_participants', 'cast' => 'int'],
            'cancellation_window_minutes' => ['key' => 'sessions.cancellation_window_minutes', 'cast' => 'int'],
            'private_request_ttl_hours' => ['key' => 'sessions.private_request_ttl_hours', 'cast' => 'int'],
            'private_request_max_pending' => ['key' => 'sessions.private_request_max_pending', 'cast' => 'int'],
            'report_delay_minutes' => ['key' => 'sessions.report_delay_minutes', 'cast' => 'int'],
            'recording_failure_alert_threshold' => ['key' => 'sessions.recording_failure_alert_threshold', 'cast' => 'int'],
            'recording_failure_alert_window_hours' => ['key' => 'sessions.recording_failure_alert_window_hours', 'cast' => 'int'],
        ];
    }

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات الحصص';
    }

    public function getTitle(): string
    {
        return 'إعدادات الحصص المباشرة';
    }

    public function mount(SessionSettings $settings): void
    {
        /*
        | ⚠️ يُقرَأُ من {@see SessionSettings} لا من `PlatformSettings::get()`
        | خامّاً — نفسُ السببِ المكتوبِ في `ManagePlatformSettings` عن أرقامِ
        | الفوترة: تلك الطرقُ تحملُ الارتدادَ إلى `config/sessions.php`، وأربعةٌ من
        | هذه المفاتيحِ **لا صفَّ لها على الإنتاج**. قراءةٌ خامٌّ تعرضُ خانةً فارغةً
        | عن رقمٍ يعملُ فعلاً، فيحفظُها المشغِّلُ صفراً ظانّاً أنّه لم يغيّرْ شيئاً
        | — وصفرٌ في نسبةِ البقاءِ المطلوبةِ يجعلُ كلَّ طالبٍ حاضراً في اللحظةِ
        | التي يدخلُ فيها.
        |
        | النِّسَبُ الثلاثُ تُقرَأُ بمفاتيحِها لأنّ `SessionSettings` لا يكشفُها
        | إلّا محلولةً في مقابلِ حصّةٍ بعينِها — وهنا لا حصّةَ نسألُ عنها.
        */
        $this->form->fill([
            'timezone' => $settings->timezone(),
            'grace_minutes' => $settings->graceMinutes(),
            'absence_threshold_ratio' => (float) PlatformSettings::get('sessions.absence_threshold_ratio', 0.5),
            'required_stay_ratio' => (float) PlatformSettings::get('sessions.required_stay_ratio', 0.5),
            'teacher_required_stay_ratio' => (float) PlatformSettings::get('sessions.teacher_required_stay_ratio', 0.8),
            'attendance_edit_window_hours' => $settings->attendanceEditWindowHours(),
            'join_window_minutes' => $settings->joinWindowMinutes(),
            'ticket_ttl_minutes' => $settings->ticketTtlMinutes(),
            'presence_interval_seconds' => $settings->presenceIntervalSeconds(),
            'max_participants' => $settings->maxParticipants(),
            'cancellation_window_minutes' => $settings->cancellationWindowMinutes(),
            'private_request_ttl_hours' => $settings->privateRequestTtlHours(),
            'private_request_max_pending' => $settings->privateRequestMaxPending(),
            'report_delay_minutes' => $settings->reportDelayMinutes(),
            'recording_failure_alert_threshold' => $settings->recordingFailureAlertThreshold(),
            'recording_failure_alert_window_hours' => $settings->recordingFailureAlertWindowHours(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    /*
                    | ⚠️ **منطقةٌ زمنيّةٌ واحدةٌ للمنتَجِ كلِّه، وهذا الصفُّ هو
                    | إعلانُها.** `GamificationCalendar` يقرأُ هذا الصفَّ بعينِه
                    | ليحسبَ حدودَ اليومِ والأسبوع؛ إعلانٌ ثانٍ في مكانٍ آخرَ هو ما
                    | يجعلُ السقفَ اليوميَّ ينزلقُ عن جدولِ الحصصِ بصمت. الأوقاتُ
                    | تُخزَّنُ UTC دائماً؛ هذه هي التي تُعرَضُ ويُعَدُّ بها.
                    */
                    Section::make('التوقيت')
                        ->description('منطقةٌ واحدةٌ يُعرَض بها كلُّ موعدٍ ويُحسَب بها حدُّ اليوم والأسبوع. التخزين UTC دائماً.')
                        ->schema([
                            // قائمةٌ لا خانةَ نصّ: اسمٌ غيرُ صالحٍ هنا يُبطِلُ حسابَ
                            // اليومِ في المكافآتِ بلا خطأٍ في أيِّ مكان.
                            Select::make('timezone')
                                ->label('المنطقة الزمنيّة')
                                ->options(array_combine(
                                    timezone_identifiers_list(),
                                    timezone_identifiers_list(),
                                ))
                                ->searchable()
                                ->required(),
                        ]),

                    /*
                    | سلّمُ الحضور (FR-021أ · FR-056).
                    |
                    | ⚠️ النِّسَبُ تُحَلُّ في مقابلِ **طولِ كلِّ حصّةٍ على حدة**
                    | داخلَ `SessionSettings::ratioOfDuration()` — «نصفُ الحصّة»
                    | قاعدةٌ واحدةٌ لا أربعُ نسخٍ تُقرِّبُ كلٌّ منها على هواها.
                    | ولذلك حدُّها الأدنى ‏٠٫٠١ لا صفر: صفرٌ في «الغياب» يُعلِنُ كلَّ
                    | مقعدٍ غائباً لحظةَ البدء، وصفرٌ في «البقاء» يجعلُ الجميعَ
                    | حاضرينَ بلا أن يبقى أحد.
                    */
                    Section::make('الحضور والغياب')
                        ->description('نِسَبٌ من طولِ الحصّة نفسِها، تُحسَب لكلّ حصّة على حدة — لا دقائقَ ثابتة.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('grace_minutes')
                                ->label('مهلة التأخير (دقائق)')
                                ->helperText('الدخولُ خلالها يُسجَّل «حاضر» لا «متأخّر».')
                                ->numeric()->minValue(0)->maxValue(120)->required(),
                            TextInput::make('absence_threshold_ratio')
                                ->label('نسبة إعلان الغياب')
                                ->helperText('مقعدٌ بلا نبضةٍ حتى هذه النسبة من طول الحصّة يُعلَن غائباً — في حينه لا في آخرها. ‏0.5 تعني النصف.')
                                ->numeric()->minValue(0.01)->maxValue(1)->step(0.05)->required(),
                            TextInput::make('required_stay_ratio')
                                ->label('نسبة البقاء المطلوبة من الطالب')
                                ->helperText('أقلُّ منها يُسجَّل «متأخّر» بدل «حاضر».')
                                ->numeric()->minValue(0.01)->maxValue(1)->step(0.05)->required(),
                            /*
                            | ⚠️ هذه وحدَها تُقرِّرُ **أجرَ المدرّس**: بقاؤه دونَها
                            | يعني أنّ الحصّةَ لم تُسلَّم، فلا `SessionDelivered` ولا
                            | وحدةَ تدريسٍ ولا قيدَ دفتر.
                            */
                            TextInput::make('teacher_required_stay_ratio')
                                ->label('نسبة البقاء المطلوبة من المدرّس')
                                ->helperText('دونها لا تُحتسَب الحصّة مُسلَّمة — ولا يُستحَقّ أجرُها.')
                                ->numeric()->minValue(0.01)->maxValue(1)->step(0.05)->required(),
                            TextInput::make('attendance_edit_window_hours')
                                ->label('مهلة تعديل الحضور يدويّاً (ساعات)')
                                ->helperText('بعدها يحتاج التعديلُ صلاحيّةً إداريّةً أعلى من صلاحيّة المدرّس نفسه.')
                                ->numeric()->minValue(0)->maxValue(720)->required(),
                        ]),

                    Section::make('الغرفة والدخول')
                        ->description('من يدخل، ومتى، وكم يبقى المفتاحُ صالحاً.')
                        ->columns(2)
                        ->schema([
                            /*
                            | ⚠️ **يسري على الحصصِ التي تُفتَحُ بعدَ الحفظِ لا على
                            | الجارية.** `OpenBroadcastRoom` يجدولُ إغلاقَ الغرفةِ
                            | بتأخيرٍ يُحسَبُ لحظةَ الفتح، فالقيمةُ الجديدةُ لا
                            | تُحرِّكُ موعدَ إغلاقِ غرفةٍ مفتوحةٍ الآن.
                            */
                            TextInput::make('join_window_minutes')
                                ->label('نافذة الدخول قبل البدء وبعد الانتهاء (دقائق)')
                                ->helperText('تسري على الحصص التي تُفتَح بعد الحفظ؛ الغرفةُ المفتوحة الآن تُغلَق بالقيمة القديمة.')
                                ->numeric()->minValue(0)->maxValue(240)->required(),
                            /*
                            | ⚠️ التذكرةُ هي البابُ كلُّه، و**المزوّدُ لا يملكُ
                            | إبطالَها**: تذكرةٌ ما تزالُ داخلَ عمرِها تُعيدُ بناءَ
                            | غرفةٍ أُغلِقَت للتوّ. ما يُبقي البابَ موصداً حارسانِ
                            | لنا — رفضُ الإصدارِ بعدَ الإغلاق، وإعادةُ السؤالِ في
                            | كلِّ نبضةِ حضور — والعمرُ القصيرُ هو ما يُقلِّصُ
                            | الفجوةَ بينَهما. والافتراضُ في مكتبةِ المزوّدِ نفسِها
                            | **أربعُ ساعات**.
                            */
                            TextInput::make('ticket_ttl_minutes')
                                ->label('عمر تذكرة الدخول (دقائق)')
                                ->helperText('المزوّد لا يستطيع إبطالَ تذكرةٍ صدرت — فالعمرُ القصير هو الحارس. الافتراض في مكتبته أربعُ ساعات.')
                                ->numeric()->minValue(1)->maxValue(360)->required(),
                            /*
                            | ⚠️ **يسافرُ على تذكرةِ الدخولِ نفسِها**
                            | (`JoinTicketResource`)، فالمتصفّحُ يلتقطُ القيمةَ
                            | الجديدةَ عندَ الدخولِ التالي بلا نشر. والخادمُ يمنحُ
                            | نبضةً واحدةً **ضعفَ** هذه المدّةِ على الأكثر، فتقصيرُها
                            | يُقلِّلُ أيضاً ما يُتسامَحُ به من انقطاعٍ واحد.
                            */
                            TextInput::make('presence_interval_seconds')
                                ->label('فاصل نبضة الحضور (ثواني)')
                                ->helperText('يصل المتصفّحَ مع تذكرة الدخول. النبضةُ الواحدة تُضيف ضعفَ هذا الرقم على الأكثر — وهو ما يمنع احتسابَ انقطاعٍ طويل حضوراً.')
                                ->numeric()->minValue(5)->maxValue(300)->required(),
                            TextInput::make('max_participants')
                                ->label('أقصى عدد داخل الغرفة')
                                ->helperText('يُمرَّر إلى المزوّد عند إنشاء الغرفة، ويشمل المدرّسَ ومُسجِّلَ الحصّة.')
                                ->numeric()->minValue(2)->maxValue(500)->required(),
                        ]),

                    Section::make('الحجز والطلبات الخاصّة')
                        ->description('متى يُفلِت المقعدُ من صاحبه، وكم طلباً خاصّاً يحتمل بريدُ المدرّس.')
                        ->columns(2)
                        ->schema([
                            /*
                            | ⚠️ هذه اللحظةُ هي التي **يُجمَّدُ عندَها عددُ المقاعدِ
                            | المحاسَبيّ**: الإلغاءُ قبلَها يُطلِقُ المقعد، وبعدَها
                            | يُحاسَبُ عليه ويُثبَّتُ العددُ الذي تُبنى عليه التسوية.
                            */
                            TextInput::make('cancellation_window_minutes')
                                ->label('نافذة الإلغاء قبل البدء (دقائق)')
                                ->helperText('قبلها يُطلَق المقعد؛ بعدها يُحاسَب عليه ويُجمَّد عددُ المقاعد الذي تُبنى عليه التسوية.')
                                ->numeric()->minValue(0)->maxValue(20160)->required(),
                            TextInput::make('private_request_ttl_hours')
                                ->label('مهلة انتظار الطلب الخاصّ (ساعات)')
                                ->helperText('بعدها ينتهي الطلبُ من نفسه بدل أن يبقى معلّقاً بلا جواب.')
                                ->numeric()->minValue(1)->maxValue(720)->required(),
                            /*
                            | ⚠️ السقفُ هو ما يجعلُ الطلبَ قابلاً للرفض: لا يحجزُ
                            | مقعداً ولا يُحرِّكُ رصيداً — وهو نفسُه ما يجعلُه
                            | رخيصاً بما يكفي لإغراقِ أسبوعِ مدرّسٍ كاملٍ في دقيقة.
                            */
                            TextInput::make('private_request_max_pending')
                                ->label('أقصى طلبات معلّقة لطالب واحد عند مدرّس واحد')
                                ->helperText('الطلبُ لا يحجز مقعداً ولا يُحرّك رصيداً — وهذا ما يجعله رخيصاً بما يكفي لإغراق جدولٍ كامل.')
                                ->numeric()->minValue(1)->maxValue(50)->required(),
                        ]),

                    Section::make('التقارير والتسجيل')
                        ->description('متى يصل تقريرُ الحصّة إلى وليّ الأمر، ومتى تسمع المنصّةُ أنّ التسجيل يفشل.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('report_delay_minutes')
                                ->label('تأخير تقرير الحصّة (دقائق)')
                                ->helperText('يُحسَب من إغلاق الغرفة، ويُمهِل المدرّسَ لتصحيح علامةِ حضورٍ قبل أن يقرأها وليُّ الأمر.')
                                ->numeric()->minValue(0)->maxValue(1440)->required(),
                            /*
                            | ⚠️ **إشارةٌ للمنصّةِ لا للمدرّس، وهذا سببُ وجودِها.**
                            | أربعونَ إشعاراً لأربعينَ مدرّساً لا تُري أحدَهم أنّ
                            | السببَ واحد؛ كلٌّ يقرؤها سوءَ حظِّه، ويبقى عطلُ
                            | المزوّدِ خلفَها لمن يجمعُ الأرقامَ صدفة. هذا هو الرقمُ
                            | الذي يقول: كُفَّ عن النظرِ في الحصصِ وانظرْ في المزوّد.
                            */
                            TextInput::make('recording_failure_alert_threshold')
                                ->label('عدد التسجيلات الفاشلة قبل تنبيه المنصّة')
                                ->helperText('تنبيهٌ واحدٌ للمنصّة بدل أربعين إشعاراً متفرّقاً يقرأ كلٌّ منها سوءَ حظٍّ فرديّ.')
                                ->numeric()->minValue(1)->maxValue(100)->required(),
                            TextInput::make('recording_failure_alert_window_hours')
                                ->label('نافذة عدّ الفشل (ساعات)')
                                ->numeric()->minValue(1)->maxValue(168)->required(),
                        ]),

                    Actions::make([
                        Action::make('save')->label('حفظ')->submit('save'),
                    ]),
                ])->livewireSubmitHandler('save'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();
        $userId = Auth::id();
        $userId = is_int($userId) ? $userId : null;

        foreach (self::fields() as $field => $spec) {
            $value = match ($spec['cast']) {
                'int' => (int) $data[$field],
                // ⚠️ `trim`، فقيمةٌ بفراغٍ في طرفِها لا تُرى في الحقلِ وتُبطِلُ
                // `new DateTimeZone()` عندَ أوّلِ عدٍّ لليوم.
                'string' => trim((string) $data[$field]),
                'float' => (float) $data[$field],
            };

            PlatformSettings::set($spec['key'], $value, $userId);
        }

        Notification::make()->success()->title('حُفظت إعدادات الحصص')->send();
    }
}
