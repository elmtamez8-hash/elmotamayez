<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Pages;

use App\Modules\Tenancy\Support\Permissions;
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
 * The family discount — one number for the whole platform (spec 011 · FR-013 · D16).
 *
 * ⚠️ `/admin`, NEVER `/manage`, AND THAT IS NOT A ROUTING PREFERENCE. The
 * discount comes out of the platform's commission, so the platform is what
 * decides it — FR-010's «whoever pays is whoever decides». A teacher able to set
 * it would be setting a discount somebody else funds.
 *
 * ⚠️ AND IT IS A SEPARATE PAGE FROM `ManagePlatformSettings` FOR A REASON THAT
 * IS NOT TIDINESS: that page is gated on `is_super_admin`, while this is a money
 * decision the delegated finance officer holds through
 * `billing.coupons.manage` — a platform permission no tenant role carries.
 * Folding this field in there would silently take it away from the person whose
 * job it is. (A super admin still reaches it: `Gate::before` answers for every
 * name in `Permissions::all()`.)
 *
 * ⚠️ THE FIELD IS A WHOLE PERCENT, AND ZERO MEANS OFF. `DiscountResolver`
 * compares it against a coupon's `percent` kind under «the highest alone
 * applies», so the two must be the same unit — basis points here would apply a
 * 10% family rate as 0.1%, silently, and only a family with two children would
 * ever be in a position to notice.
 *
 * @property-read Schema $form
 */
class ManageSiblingDiscount extends Page
{
    protected string $view = 'filament.pages.manage-sibling-discount';

    protected static ?string $slug = 'sibling-discount';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 26;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::BILLING_COUPONS_MANAGE) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'خصم الإخوة';
    }

    public function getTitle(): string
    {
        return 'خصم الإخوة';
    }

    public function mount(): void
    {
        $this->form->fill([
            'sibling_discount' => (int) PlatformSettings::get('billing.sibling_discount', 0),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('خصم الإخوة')
                        ->description('يُطبَّق تلقائيّاً بلا كودٍ ولا طلب، على كلِّ طالبٍ يشترك مع أخٍ مسجَّلٍ '
                            .'في وليِّ الأمرِ نفسِه. قيمةٌ واحدةٌ تسري عند كلِّ مدرّس، وتخرج من عمولة '
                            .'المنصّة وحدَها فلا تمسّ استحقاق المدرّس.')
                        ->schema([
                            TextInput::make('sibling_discount')
                                ->label('نسبة الخصم (٪)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->required()
                                ->helperText('صفر يعني إيقاف الخصم. والاكتشافُ من علاقةِ وليِّ الأمرِ المُثبَتة، '
                                    .'لا من رقمِ الهاتف. وإن اجتمع مع كوبونٍ طُبِّق الأعلى وحدَه بلا تراكم.'),
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

        // Clamped on the way in as well as on the way out. `SiblingDiscount`
        // clamps what it reads because a seeder or a console command can write
        // this row with no form behind it; clamping here too means the number an
        // operator sees back is the number that is actually in force.
        PlatformSettings::set(
            'billing.sibling_discount',
            max(0, min(100, (int) $data['sibling_discount'])),
            $userId,
        );

        Notification::make()->success()->title('حُفظ خصم الإخوة')->send();
    }
}
