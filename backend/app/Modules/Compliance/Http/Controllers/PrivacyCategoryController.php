<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Http\Resources\DataCategoryResource;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Models\DataProcessor;
use App\Modules\Courses\Support\MarkdownRenderer;
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
                    'purpose_ar' => $processor->purpose_ar,
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
    public function policy(): JsonResponse
    {
        $path = resource_path('policies/privacy-ar.md');
        $source = is_file($path) ? (string) file_get_contents($path) : '';

        return response()->json([
            'version' => (string) config('consents.versions.data_processing', '1.0'),
            'body_html' => MarkdownRenderer::toHtml($source),
        ]);
    }
}
