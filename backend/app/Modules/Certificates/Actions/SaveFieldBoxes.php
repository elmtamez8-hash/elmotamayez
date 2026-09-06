<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Actions;

use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Where the six fields sit on one design.
 *
 * ⚠️ THE WHOLE STRUCTURE IS ENFORCED HERE, NOT IN THE FORM REQUEST ALONE
 * (المبدأ الثاني). A rule written only in a request is a rule the seeder and the
 * Filament panel walk straight past — and what they would write is a box outside
 * the image, or a `min_font` above its `max_font`, which is a field the browser
 * draws where nobody can see it. The request validates the SHAPE of the payload;
 * this class validates the MEANING, and it is the only door.
 */
class SaveFieldBoxes extends Action
{
    /**
     * ⚠️ A CLOSED LIST, AND ITS TWIN IS `INK` IN `CertificateArtwork.tsx`.
     * Tailwind emits no rule for a token `@theme` has never seen and CSS resolves
     * an unknown `var()` to nothing, so an unrecognised name here would be text
     * that is simply INVISIBLE, with no error anywhere — the defect this tree has
     * shipped four times. The component falls back rather than paint nothing; this
     * refuses rather than store it. Change one list and change the other.
     */
    public const COLORS = ['certificate-ink', 'ink', 'ink-muted'];

    private const ALIGNMENTS = ['start', 'center', 'end'];

    /**
     * @param  array<mixed>|null  $boxes  null = go back to the registry's positions
     */
    public function handle(CertificateDesign $design, ?array $boxes): CertificateDesign
    {
        if ($boxes === null) {
            /*
            | ⚠️ «RESET» MEANS «FALL BACK TO THE TEMPLATE THIS ROW ADOPTED», and an
            | uploaded design adopted nothing. Clearing its `field_boxes` would not
            | restore anything — it would make the design UNREADY while it is the
            | selected one, i.e. an image with the six fields nowhere. Refused with
            | a reason (`FR-021`).
            */
            if ($design->system_key === null) {
                throw new DomainException('لا مواضع أصلية لتصميم مرفوع — اضبط المواضع بنفسك أو احذف التصميم.');
            }

            $design->update(['field_boxes' => null]);

            return $design->refresh();
        }

        $design->update(['field_boxes' => $this->clean($boxes)]);

        return $design->refresh();
    }

    /**
     * @param  array<mixed>  $boxes
     * @return array<string, array<string, mixed>>
     */
    private function clean(array $boxes): array
    {
        /*
        | ⚠️ THE SIX KEYS EXACTLY — no fewer and no more. A partial write is the
        | trap: five keys stored means the sixth is read from nowhere and the field
        | lands wherever the fallback puts it, over the artwork's ornament. And an
        | unknown key is a typo that would sit in the column for ever, silently
        | doing nothing.
        */
        $keys = array_keys($boxes);
        sort($keys);
        $expected = CertificateTemplateRegistry::FIELDS;
        sort($expected);

        if ($keys !== $expected) {
            throw new DomainException('مواضع الحقول يجب أن تحمل الحقول الستّة كاملةً ولا شيء غيرها.');
        }

        $clean = [];

        foreach (CertificateTemplateRegistry::FIELDS as $key) {
            $box = $boxes[$key];

            if (! is_array($box)) {
                throw new DomainException("موضع الحقل «{$key}» غير صالح.");
            }

            $clean[$key] = $key === 'qr'
                ? $this->geometry($key, $box)
                : $this->geometry($key, $box) + $this->typography($key, $box);
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $box
     * @return array<string, float>
     */
    private function geometry(string $key, array $box): array
    {
        $x = $this->fraction($key, $box, 'x');
        $y = $this->fraction($key, $box, 'y');
        $w = $this->fraction($key, $box, 'w');
        $h = $this->fraction($key, $box, 'h');

        // ⚠️ `x + w ≤ 1` is a separate rule from `x ≤ 1` (`FR-020`): a box that
        // starts inside the image and ends outside it is exactly the case a naive
        // range check lets through, and it is the common one — dragging right.
        if ($x + $w > 1.0 || $y + $h > 1.0) {
            throw new DomainException("موضع الحقل «{$key}» يخرج عن حدود الصورة.");
        }

        if ($w <= 0.0 || $h <= 0.0) {
            throw new DomainException("مساحة الحقل «{$key}» يجب أن تكون أكبر من صفر.");
        }

        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    /**
     * @param  array<mixed>  $box
     * @return array<string, mixed>
     */
    private function typography(string $key, array $box): array
    {
        $max = $this->fraction($key, $box, 'max_font');
        $min = $this->fraction($key, $box, 'min_font');

        if ($min > $max) {
            throw new DomainException("أصغر خطّ للحقل «{$key}» أكبر من أكبره.");
        }

        $align = $box['align'] ?? 'center';
        $color = $box['color'] ?? 'certificate-ink';

        if (! is_string($align) || ! in_array($align, self::ALIGNMENTS, true)) {
            throw new DomainException("محاذاة الحقل «{$key}» غير معروفة.");
        }

        if (! is_string($color) || ! in_array($color, self::COLORS, true)) {
            throw new DomainException("لون الحقل «{$key}» غير معروف.");
        }

        return ['align' => $align, 'max_font' => $max, 'min_font' => $min, 'color' => $color];
    }

    /** @param array<mixed> $box */
    private function fraction(string $key, array $box, string $field): float
    {
        $value = $box[$field] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new DomainException("قيمة «{$field}» للحقل «{$key}» مفقودة أو ليست رقماً.");
        }

        $value = (float) $value;

        // ⚠️ Fractions of the image, never pixels (`FR-022`): a layout measured in
        // pixels is true on the screen of whoever measured it and lies on every
        // phone and on paper.
        if (! is_finite($value) || $value < 0.0 || $value > 1.0) {
            throw new DomainException("قيمة «{$field}» للحقل «{$key}» خارج المدى من صفر إلى واحد.");
        }

        return $value;
    }
}
