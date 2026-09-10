<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Resources;

use App\Modules\Certificates\Models\CertificateDesign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE SHAPE for a shipped template and an uploaded design, so the gallery does
 * not branch on which it is holding.
 *
 * ⚠️ A shipped template a workspace has NOT adopted has no row and therefore no
 * uuid — `uuid: null` is what says "adopt me with a POST" — and that is why
 * {@see fromTemplate} exists beside `toArray()` rather than a second Resource: two
 * classes for one card is two places for `is_ready` to be spelled differently.
 *
 * `is_ready` is DERIVED and `boxes` come off the model's own accessors, which are
 * the same ones `ResolveCertificateDesign` answers the public page with. A gallery
 * that computed either itself would preview something the certificate does not do.
 *
 * @mixin CertificateDesign
 */
class CertificateDesignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'system_key' => $this->system_key,
            'name' => $this->name ?? $this->template()['name'] ?? null,
            'image_url' => $this->imageUrl(),
            'source' => $this->system_key !== null ? 'system' : 'uploaded',
            'is_selected' => $this->selected_for_workspace_id !== null,
            'is_ready' => $this->isReady(),
            'boxes' => $this->effectiveBoxes(),
        ];
    }

    /**
     * The same card for a shipped template nobody has adopted yet.
     *
     * @param  array{key: string, name: string, image_url: string, is_default: bool, boxes: array<string, array<string, mixed>>}  $template
     * @return array<string, mixed>
     */
    public static function fromTemplate(array $template): array
    {
        return [
            'uuid' => null,
            'system_key' => $template['key'],
            'name' => $template['name'],
            'image_url' => $template['image_url'],
            'source' => 'system',
            // No row, so nothing can have selected it.
            'is_selected' => false,
            // A shipped template arrives with its positions measured off its own artwork.
            'is_ready' => true,
            'boxes' => $template['boxes'],
        ];
    }
}
