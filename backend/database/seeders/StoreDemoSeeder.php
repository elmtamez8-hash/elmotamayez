<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Store\Enums\StoreItemKind;
use App\Modules\Store\Models\StoreItem;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * FAKE store data, for testing the store by hand — never production
 * (`DatabaseSeeder` calls it only outside it). Run on its own with:
 *
 *     php artisan db:seed --class=StoreDemoSeeder
 *
 * Every LISTED teacher (the marketplace's own predicate) gets five products:
 * two printed (one nearly sold out), two digital with a real, openable PDF, and
 * one hidden draft that must NOT appear on the public store — so the filters,
 * the price sort, «نفدت/بقي», the buy flow and «افتح الملف» all have something to
 * act on. Covers are generated pictures on the public disk.
 *
 * Idempotent: a teacher who already has a product is skipped, so a re-run adds
 * only what is missing and never doubles a shelf.
 */
class StoreDemoSeeder extends Seeder
{
    /** Five products per teacher; `{s}` is the teacher's first subject's name. */
    private const SHELF = [
        ['kind' => 'physical', 'title' => 'مذكّرة {s} الشاملة', 'price' => 15000, 'stock' => 40, 'shipping' => 5000, 'course' => true,
            'excerpt' => 'مذكّرة مطبوعة تغطّي المنهج كاملاً مع تمارين محلولة.',
            'description' => "## ماذا في المذكّرة؟\n\n- شرح مبسّط لكل درس\n- **٢٠٠ سؤال** بالحلول التفصيلية\n- ملخّص القوانين في آخر كل باب\n\nمناسبة للمراجعة قبل الامتحان وللمذاكرة اليومية."],
        ['kind' => 'digital', 'title' => 'ملخّص {s} — نسخة PDF', 'price' => 4900,
            'excerpt' => 'ملخّص مركّز في ٤٠ صفحة، يصلك فور اعتماد الدفع.',
            'description' => "ملخّص رقمي بصيغة **PDF** لكل أبواب المنهج.\n\n1. القوانين والتعريفات\n2. أشهر أفكار الامتحانات\n3. خرائط ذهنية لكل باب"],
        ['kind' => 'digital', 'title' => 'بنك أسئلة {s} بالحلول', 'price' => 7550,
            'excerpt' => 'أسئلة امتحانات السنوات السابقة مع الحلول النموذجية.',
            'description' => 'أكثر من **٥٠٠ سؤال** مقسّمة حسب الدرس، مع نموذج إجابة لكل سؤال.'],
        ['kind' => 'physical', 'title' => 'كتاب المراجعة النهائية — {s}', 'price' => 22000, 'stock' => 3, 'shipping' => 0,
            'excerpt' => 'آخر ما تحتاجه قبل الامتحان. الكمية محدودة.',
            'description' => 'كتاب المراجعة الليلة الأخيرة: نماذج امتحانات كاملة بتوقيتها، والشحن مجاني.'],
        ['kind' => 'digital', 'title' => 'مسوّدة لم تُعرض — {s}', 'price' => 1000, 'hidden' => true,
            'excerpt' => 'منتج مخفي: يجب ألا يظهر في المتجر العام.', 'description' => null],
    ];

    /** One colour per product slot, so the covers are told apart at a glance. */
    private const COLOURS = [[128, 31, 58], [31, 94, 128], [46, 120, 72], [150, 96, 20], [90, 90, 90]];

    public function run(): void
    {
        // Fake products on a live store would be sold to real students.
        if (app()->environment('production')) {
            $this->command->warn('StoreDemoSeeder is local-only; skipped in production.');

            return;
        }

        $currency = app(BillingSettings::class)->currency();

        $profiles = TeacherProfile::query()
            ->publiclyListed()
            ->with(['user', 'subjects'])
            ->get();

        foreach ($profiles as $profile) {
            if (StoreItem::query()->withoutWorkspaceScope()->where('teacher_profile_id', $profile->getKey())->exists()) {
                continue;
            }

            app(WorkspaceContext::class)->forWorkspace((int) $profile->workspace_id, function () use ($profile, $currency): void {
                $subject = $profile->subjects->first();
                $subjectName = $subject === null ? 'المادة' : (string) $subject->getAttribute('name');

                $course = Course::query()
                    ->withoutWorkspaceScope()
                    ->where('workspace_id', $profile->workspace_id)
                    ->where('status', 'published')
                    ->orderBy('id')
                    ->first();

                foreach (self::SHELF as $slot => $spec) {
                    $item = new StoreItem([
                        'workspace_id' => $profile->workspace_id,
                        'teacher_profile_id' => $profile->getKey(),
                        'subject_id' => $subject?->getKey(),
                        'course_id' => ($spec['course'] ?? false) ? $course?->getKey() : null,
                        'kind' => StoreItemKind::from($spec['kind']),
                        'title' => str_replace('{s}', $subjectName, $spec['title']),
                        'excerpt' => $spec['excerpt'],
                        'description' => $spec['description'],
                        'price_minor' => $spec['price'],
                        'currency' => $currency,
                        'shipping_fee_minor' => $spec['kind'] === 'physical' ? $spec['shipping'] : null,
                        'is_active' => ! ($spec['hidden'] ?? false),
                    ]);
                    // `stock` is not fillable — it moves by a conditional UPDATE.
                    $item->stock = $spec['kind'] === 'physical' ? $spec['stock'] : null;
                    $item->save();

                    $item->forceFill(['cover_path' => $this->cover($item, self::COLOURS[$slot])])->save();

                    if ($spec['kind'] === 'digital') {
                        $item->forceFill(['media_asset_id' => $this->pdfFor($item)->getKey()])->save();
                    }
                }
            });
        }
    }

    /**
     * A 600×800 cover: a coloured page with a lighter «spine» and title band.
     *
     * @param  array{int<0, 255>, int<0, 255>, int<0, 255>}  $rgb
     */
    private function cover(StoreItem $item, array $rgb): string
    {
        $image = imagecreatetruecolor(600, 800);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]));
        imagefilledrectangle($image, 0, 0, 40, 800, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 80, 300, 560, 420, (int) imagecolorallocatealpha($image, 255, 255, 255, 80));
        imagefilledellipse($image, 480, 680, 140, 140, (int) imagecolorallocatealpha($image, 255, 255, 255, 90));

        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();

        $path = 'store-covers/demo-'.$item->uuid.'.jpg';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /** A real, openable one-page PDF, stored where the local provider serves it from. */
    private function pdfFor(StoreItem $item): MediaAsset
    {
        $provider = app(LocalMediaProvider::class);

        $asset = new MediaAsset([
            'workspace_id' => $item->workspace_id,
            'owner_type' => StoreItem::class,
            'owner_id' => $item->getKey(),
            'provider' => $provider->identifier(),
            'kind' => MediaKind::Document,
            'role' => MediaRole::Attachment,
            'is_downloadable' => false,
            'status' => MediaAssetStatus::Ready,
            'original_filename' => 'sample.pdf',
            'mime_type' => 'application/pdf',
            'ready_at' => now(),
        ]);
        $asset->save();

        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            ."4 0 obj<</Length 44>>stream\nBT /F1 24 Tf 72 760 Td (Demo store file) Tj ET\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n";

        $path = $provider->pathFor($asset);
        $provider->disk()->put($path, $pdf);

        $asset->forceFill(['provider_asset_id' => $path, 'size_bytes' => strlen($pdf)])->save();

        return $asset;
    }
}
