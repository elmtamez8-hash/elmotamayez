<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Pages;

use App\Modules\Analytics\Actions\ReadPlatformAnalytics;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The platform dashboard (spec 011 · FR-040 · FR-046).
 *
 * ⚠️ `analytics.cross_teacher.view`, NOT `analytics.view`. The second is a
 * WORKSPACE permission — it is in the assistant's matrix, and it answers «may you
 * see your own teacher's numbers». This screen adds every workspace on the
 * platform together, so guarding it with that name would hand one teacher's
 * assistant the totals of every competitor they have.
 *
 * ⚠️ AND IT DECLARES `canAccess()` OF ITS OWN. `PanelResourceDoorTest` walks
 * `getResources()` alone — a PAGE with no door ships and nothing fails, which is
 * the `taxonomy.manage` defect reached from a third direction. A super admin
 * still gets in: `Gate::before` answers for every name in `Permissions::all()`.
 */
class PlatformAnalytics extends Page
{
    protected string $view = 'filament.pages.platform-analytics';

    protected static ?string $slug = 'platform-analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed> */
    public array $report = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::ANALYTICS_CROSS_TEACHER_VIEW) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'تحليلات المنصّة';
    }

    public function getTitle(): string
    {
        return 'تحليلات المنصّة';
    }

    public function mount(ReadPlatformAnalytics $action): void
    {
        // Read from the rollup, never computed here (FR-044). If today's pass has
        // not run yet the numbers are zeros with today's date on them, which is
        // the honest answer — inventing them from the source tables is the scan
        // that requirement forbids.
        $this->report = $action->handle();
    }
}
