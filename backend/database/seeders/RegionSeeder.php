<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Marketplace\Models\Region;
use Illuminate\Database\Seeder;

/**
 * The regions a student picks at registration (spec 011 · FR-042).
 *
 * ⚠️ THIS IS THE FOURTH RUNTIME CATALOGUE IN THE TREE AND THE SHARPEST OF THEM.
 * The other three fail quietly — a notification with no template is dropped, a
 * data category with no row is never swept, an unknown gamification action
 * awards nothing. This one fails LOUDLY at the worst possible door: `region_slug`
 * is required by `RegisterStudentRequest`, so an empty table on a live database
 * means EVERY new registration is answered 422 in front of a picker with nothing
 * in it. Hence the backfill migration beside this file.
 *
 * ⚠️ TWO MODES, exactly as `NotificationTemplateSeeder` and `DataCategorySeeder`.
 * `run()` overwrites and belongs to `migrate:fresh --seed`; `seedMissing()` adds
 * only what is absent and is what a deploy may call — every row here is editable
 * from `/admin`, and an `updateOrCreate` in the deploy path resets an operator's
 * renaming on every release.
 */
class RegionSeeder extends Seeder
{
    public function run(): void
    {
        $this->write(overwrite: true);
    }

    public function seedMissing(): void
    {
        $this->write(overwrite: false);
    }

    private function write(bool $overwrite): void
    {
        foreach ($this->regions() as $order => [$slug, $name]) {
            $attributes = ['name_ar' => $name, 'sort_order' => $order, 'is_active' => true];

            if ($overwrite) {
                Region::query()->updateOrCreate(['slug' => $slug], $attributes);

                continue;
            }

            Region::query()->firstOrCreate(['slug' => $slug], $attributes);
        }
    }

    /**
     * Qatar's municipalities, plus one for everybody else.
     *
     * ⚠️ «خارج قطر» IS NOT PADDING. The field is required, the marketplace is
     * open to a student anywhere, and a required picker with no row that fits
     * them is a registration they cannot complete — the same wall as an empty
     * table, reached by one person at a time.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function regions(): array
    {
        return [
            ['doha', 'الدوحة'],
            ['al-rayyan', 'الريان'],
            ['al-wakrah', 'الوكرة'],
            ['umm-salal', 'أم صلال'],
            ['al-khor', 'الخور والذخيرة'],
            ['al-shamal', 'الشمال'],
            ['al-daayen', 'الظعاين'],
            ['al-shahaniya', 'الشحانية'],
            ['outside-qatar', 'خارج قطر'],
        ];
    }
}
