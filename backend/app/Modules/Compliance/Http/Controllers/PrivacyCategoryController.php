<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Http\Resources\DataCategoryResource;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Models\DataProcessor;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Modules\Courses\Support\MarkdownRenderer;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Contracts\ConsentDirectory;
use Illuminate\Http\JsonResponse;

/**
 * What we collect, why, for how long, and who else sees it (FR-004 · FR-024).
 *
 * ⚠️ THE RETENTION IS TWO COLUMNS ON THIS TABLE, not a joined row. The design
 * originally had a `retention_rules` table in a 1:1 relationship with this one —
 * two columns wearing a table — and cutting it removed a QUERY PER CATEGORY from
 * this exact endpoint. The simplification fixed an N+1 for free.
 */
class PrivacyCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = DataCategory::query()
            // Required first, then by module: the required/optional split is the
            // one distinction a reader has to be able to make, so it drives the
            // order rather than being a badge buried in a long list.
            ->orderByDesc('is_required')
            ->orderBy('owning_module')
            ->orderBy('key')
            ->get();

        return response()->json([
            'data' => DataCategoryResource::collection($categories),
            /*
            | The third-party register beside the categories, deliberately. FR-024
            | asks who receives the data, and answering that on a separate screen
            | means the person reading about their child's recording has to go
            | looking for who stores it.
            */
            'processors' => DataProcessor::query()
                ->where('is_active', true)
                ->orderBy('key')
                ->get()
                ->map(fn (DataProcessor $processor): array => [
                    'key' => $processor->key,
                    'name' => $processor->name,
                    'purpose' => $processor->purpose,
                    'processing_location' => $processor->processing_location,
                    'categories' => $processor->categories,
                    'erasure_capability' => $processor->erasure_capability->value,
                    'erasure_capability_label_ar' => $processor->erasure_capability->label(),
                ])
                ->all(),
        ]);
    }

    /**
     * The policy text itself.
     *
     * ⚠️ MARKDOWN RENDERED PER RESPONSE, NEVER STORED AS HTML — the repository rule
     * from 016, and it applies with more force here: `MarkdownRenderer` STRIPS raw
     * HTML rather than escaping it, so the allowlist is the Markdown feature set
     * and there is no sanitiser configuration to get wrong. A stored `content_html`
     * would be a second copy of the same words that drifts from the source at the
     * first correction.
     *
     * ⚠️ AND THE VERSION TRAVELS WITH THE TEXT. A consent names the version it was
     * given for, so a client that displayed one version and submitted another would
     * record a signature against words nobody read.
     */
    public function policy(ConsentDirectory $consent): JsonResponse
    {
        $path = resource_path('policies/privacy-ar.md');
        $source = is_file($path) ? (string) file_get_contents($path) : '';

        return response()->json([
            /*
            | ⚠️ THE SAME READER THE SIGNATURE IS CHECKED AGAINST. This read
            | `config()` alone while `PrivacyConsentController` asked the
            | directory, which reads `platform_settings` first — so an operator who
            | published a version from the panel had the page show one number and
            | the consent endpoint demand another, and every signature answered 409.
            */
            'version' => $consent->currentVersion('data_processing'),
            'body_html' => MarkdownRenderer::toHtml(self::fill($source)),
        ]);
    }

    /**
     * The FACTS the text quotes, filled from where they are actually kept.
     *
     * ⚠️ FACTS ONLY — a name, a contact line, a duration. Every one of them is
     * edited by an operator (`platform_settings`), so writing them into the file
     * would make the policy wrong the day somebody changed the panel: «خلال
     * المدّة المعلَنة هناك» was the shape of that, a promise pointing at a number
     * the text never stated. The SUBSTANCE of the policy stays in the file,
     * because a signature names the version of the words, not of the settings.
     *
     * ⚠️ AND A LINE WHOSE VALUE IS EMPTY GOES WHOLE. A cleared support number
     * must remove the sentence offering it, never print «واتساب: » with nothing
     * after it. The values are escaped because they become Markdown source: a
     * platform name carrying `*` or `[` would otherwise be read as formatting.
     */
    private static function fill(string $source): string
    {
        $values = [
            '{{platform_name}}' => (string) PlatformSettings::get('platform.name'),
            '{{support_whatsapp}}' => (string) preg_replace('/\D/', '', (string) PlatformSettings::get('platform.support_whatsapp')),
            '{{request_due_days}}' => strtr((string) ComplianceSettings::requestDueDays(), [
                '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
                '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
            ]),
        ];

        $lines = [];

        /*
        | ⚠️ `explode`, NEVER `preg_split('/\R/')` without `/u`: `\R` matches the
        | byte 0x85, which sits INSIDE half the Arabic letters in UTF-8, so the
        | split cut characters in two and CommonMark refused the whole document.
        */
        foreach (explode("\n", str_replace("\r\n", "\n", $source)) as $line) {
            foreach ($values as $token => $value) {
                if (! str_contains($line, $token)) {
                    continue;
                }

                if (trim($value) === '') {
                    continue 2;
                }

                $line = str_replace($token, addcslashes(trim($value), '\`*_[]<>#'), $line);
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
