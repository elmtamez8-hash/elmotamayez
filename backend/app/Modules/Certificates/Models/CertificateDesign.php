<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Models;

use App\Models\BaseModel;
use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;

/**
 * A workspace's certificate design: an adopted shipped template, or one the
 * teacher uploaded.
 *
 * @property string|null $name
 * @property string|null $system_key
 * @property string|null $image_path
 * @property array<string, mixed>|null $field_boxes
 * @property int|null $selected_for_workspace_id
 */
class CertificateDesign extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    /*
    | ⚠️ THREE COLUMNS ARE DELIBERATELY ABSENT.
    |
    | `selected_for_workspace_id` is CLAIMED inside the transaction that owns the
    | switch; mass-assignable it becomes a second way to take that lock from
    | outside — the `captured_order_id` rule.
    |
    | `image_path` is written by the upload Action AFTER the file has been
    | re-encoded, so a fillable one would let a request name a path on disk.
    |
    | `workspace_id` is filled by `BelongsToWorkspace`.
    */

    protected $fillable = [
        'name',
        'system_key',
        'field_boxes',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'field_boxes' => 'array',
        ];
    }

    /**
     * Ready to be selected.
     *
     * ⚠️ DERIVED, WITH NO COLUMN BEHIND IT. An adopted shipped design is ready the
     * moment it exists (the registry supplies its positions); an uploaded one is
     * ready only once someone has said where the six fields go. A stored flag is
     * a second answer to a question these two columns already answer, and it
     * drifts the first time a design is reset to the registry's positions.
     */
    public function isReady(): bool
    {
        return $this->system_key !== null || $this->field_boxes !== null;
    }

    /**
     * The shipped template this row adopted, if it adopted one.
     *
     * @return array{key: string, name: string, image_url: string, is_default: bool, boxes: array<string, array<string, mixed>>}|null
     */
    public function template(): ?array
    {
        return CertificateTemplateRegistry::find($this->system_key);
    }

    /**
     * The artwork this design draws on.
     *
     * ⚠️ THIS AND {@see effectiveBoxes} ARE THE ONLY SPELLING OF THE DERIVATION,
     * and that is the point. `ResolveCertificateDesign` answers the public verify
     * page with them and `CertificateDesignResource` answers the gallery with
     * them — a second derivation beside either is how the preview stops matching
     * the certificate it previews, which is the worst thing a position editor can
     * do to a teacher.
     */
    public function imageUrl(): string
    {
        if ($this->image_path !== null) {
            return asset('storage/'.$this->image_path);
        }

        $template = $this->template() ?? CertificateTemplateRegistry::default();

        return $template['image_url'];
    }

    /**
     * Where the six fields sit, with the registry standing in for an adopted row
     * that has never been adjusted.
     *
     * ⚠️ `null` MEANS «NOT READY», AND ONLY AN UPLOAD CAN REACH IT: an adopted row
     * always has the registry behind it. It is returned rather than defaulted so
     * the gallery can say «يحتاج ضبطاً» instead of drawing a design over an image
     * whose boxes were measured for another one.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function effectiveBoxes(): ?array
    {
        if ($this->field_boxes !== null) {
            return $this->field_boxes;
        }

        return $this->template()['boxes'] ?? null;
    }
}
