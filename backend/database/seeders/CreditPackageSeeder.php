<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Models\CreditPackage;
use Illuminate\Database\Seeder;

/**
 * The credit catalogue — reference data, in the sense NotificationTemplateSeeder
 * is reference data (spec 006 · FR-016 · FR-019).
 *
 * ⚠️ WITHOUT THIS THE PRODUCT CANNOT TAKE MONEY. `credit_packages` shipped empty
 * and the only way to add a row was a hand-written POST, so the student's
 * purchase screen answered "لا توجد حزم متاحة" on every fresh install — while
 * the launch default mode is PREPAID_CREDITS, in which a student who cannot buy
 * cannot book. An empty catalogue is not a neutral starting point; it is the
 * whole revenue path switched off.
 *
 * ⚠️ SEEDED, THEN OWNED BY THE OPERATOR. `firstOrCreate` on the name and nothing
 * else: a re-seed on the next deploy must not resurrect a size somebody retired
 * in the panel, nor undo a re-ordering. Reference data at birth, operator data
 * for ever after — which is why this is not `updateOrCreate`.
 *
 * ⚠️ NO PRICE, HERE OR ANYWHERE ON THE ROW. The price is `(that course's
 * teacher's approved rate + a platform constant) × credits`, so a number stored
 * here would be one price for every teacher on the platform. See CostPlusPricing.
 *
 * Both session types are seeded, and the list does not become six packages for
 * everyone: `CostPlusPricing::price()` resolves the approved rate for THE
 * PACKAGE'S OWN TYPE and returns null when the teacher holds none, and
 * `ListCreditPackages` drops a null price. A teacher approved for individual
 * sessions alone therefore offers three sizes, not six — the catalogue filters
 * itself against what each teacher was actually approved to teach.
 *
 * `validity_days` is null on every row (Q-5). Credits do not expire at launch,
 * and seeding a validity would switch on a policy the product has never sold —
 * ExpireCreditLotsJob would start taking sessions off people who were never told
 * they could lose them.
 */
class CreditPackageSeeder extends Seeder
{
    /**
     * Three sizes per type. Three rather than one because the student's decision
     * is "how much do I commit", and rather than five because every extra row is
     * a column of arithmetic on a phone screen.
     *
     * @var list<array{string, int, ClassSessionType, int}>
     */
    private const CATALOGUE = [
        ['أربع حصص فردية', 4, ClassSessionType::Individual, 1],
        ['ثماني حصص فردية', 8, ClassSessionType::Individual, 2],
        ['اثنتا عشرة حصة فردية', 12, ClassSessionType::Individual, 3],
        ['أربع حصص جماعية', 4, ClassSessionType::Group, 4],
        ['ثماني حصص جماعية', 8, ClassSessionType::Group, 5],
        ['اثنتا عشرة حصة جماعية', 12, ClassSessionType::Group, 6],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as [$name, $credits, $type, $order]) {
            CreditPackage::query()->firstOrCreate(
                ['name' => $name],
                [
                    'credits' => $credits,
                    'session_type' => $type,
                    'validity_days' => null,
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );
        }
    }
}
