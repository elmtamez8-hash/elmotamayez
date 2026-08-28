<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Pages;

use App\Models\User;
use App\Modules\Tenancy\Support\PlatformSettings;
use BackedEnum;
use Filament\Actions\Action;
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
 * The operational dials, editable without a deploy.
 *
 * Platform-level, so the gate is `is_super_admin` and not a tenant permission:
 * the device limit governs a student account that enrols with many teachers, and
 * no single teacher may decide it.
 *
 * @property-read Schema $form
 */
class ManagePlatformSettings extends Page
{
    protected string $view = 'filament.pages.manage-platform-settings';

    protected static ?string $slug = 'platform-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات المنصّة';
    }

    public function getTitle(): string
    {
        return 'إعدادات المنصّة';
    }

    public function mount(): void
    {
        /** @var array<string, int> $limits */
        $limits = PlatformSettings::get('auth.device_limits', []);

        $this->form->fill([
            'student_device_limit' => $limits['student'] ?? 1,
            'two_factor_grace_days' => PlatformSettings::get('auth.two_factor_grace_days'),
            'max_size_bytes' => PlatformSettings::get('media.max_size_bytes'),
            'max_duration_seconds' => PlatformSettings::get('media.max_duration_seconds'),
            'grant_ttl_seconds' => PlatformSettings::get('media.grant_ttl_seconds'),
            'max_renewals' => PlatformSettings::get('media.max_renewals'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('الأجهزة والحسابات')
                        ->description('عدد الأجهزة لا الجلسات: جلستان على الجهاز نفسه تبقيان معاً.')
                        ->schema([
                            TextInput::make('student_device_limit')
                                ->label('عدد الأجهزة النشطة لحساب الطالب')
                                ->helperText('الدخول من جهاز إضافي يُنهي جلسات أقدم جهاز تلقائياً.')
                                ->numeric()->minValue(1)->maxValue(10)->required(),
                            TextInput::make('two_factor_grace_days')
                                ->label('مهلة إلزام التحقق الثنائي (بالأيام)')
                                ->numeric()->minValue(0)->maxValue(365)->required(),
                        ]),
                    Section::make('الفيديو')
                        ->description('هذه هي الحدودُ المعلَنةُ للمزوّد والمفروضةُ عند الرفع معاً؛ رقمان مختلفان يعني وعداً يخالف ما يُقبَل.')
                        ->schema([
                            TextInput::make('max_size_bytes')
                                ->label('الحد الأقصى لحجم الملف (بايت)')
                                ->numeric()->minValue(1)->required(),
                            TextInput::make('max_duration_seconds')
                                ->label('الحد الأقصى لمدة الفيديو (ثانية)')
                                ->numeric()->minValue(1)->required(),
                            TextInput::make('grant_ttl_seconds')
                                ->label('عمر منحة التشغيل (ثانية)')
                                ->helperText('تُجدَّد تلقائياً أثناء المشاهدة؛ القيمة القصيرة تعني توقّفاً أسرع عند انتهاء الجلسة.')
                                ->numeric()->minValue(30)->required(),
                            TextInput::make('max_renewals')
                                ->label('أقصى عدد تجديدات لجلسة مشاهدة واحدة')
                                ->numeric()->minValue(1)->required(),
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

        PlatformSettings::set('auth.device_limits', ['student' => (int) $data['student_device_limit']], $userId);
        PlatformSettings::set('auth.two_factor_grace_days', (int) $data['two_factor_grace_days'], $userId);
        PlatformSettings::set('media.max_size_bytes', (int) $data['max_size_bytes'], $userId);
        PlatformSettings::set('media.max_duration_seconds', (int) $data['max_duration_seconds'], $userId);
        PlatformSettings::set('media.grant_ttl_seconds', (int) $data['grant_ttl_seconds'], $userId);
        PlatformSettings::set('media.max_renewals', (int) $data['max_renewals'], $userId);

        Notification::make()->success()->title('حُفظت الإعدادات')->send();
    }
}
