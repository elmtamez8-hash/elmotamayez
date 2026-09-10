<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Support;

/**
 * The certificate designs the PLATFORM ships.
 *
 * ⚠️ A CODE REGISTRY, NOT A TABLE, AND THAT IS THE WHOLE POINT. The constitution
 * classifies this as platform-owned reference data whose guard is "who may write
 * it" — and the answer here is nobody at all, not even a super admin. A table
 * with no writer needs a seeder, and a runtime catalogue seeded only by
 * `migrate:fresh --seed` NEVER REACHES AN EXISTING DATABASE: this tree has paid
 * for that exact defect five times (notification templates, data categories,
 * gamification actions, regions, taxonomy), and each time the symptom was
 * silence. A constant ships with the code that reads it, so it cannot be absent.
 *
 * Adding a design is: commit the WebP under `frontend/public/certificate-templates/`,
 * add a row here, and let `TemplateRegistryTest` prove the file exists.
 */
final class CertificateTemplateRegistry
{
    /** The only key a certificate falls back to when a workspace has chosen nothing. */
    public const DEFAULT_KEY = 'classic';

    /**
     * ⚠️ POSITIONS ARE FRACTIONS OF THE IMAGE (0–1), NEVER PIXELS. A layout
     * measured in pixels is true on the screen of whoever measured it and lies on
     * every phone and on paper. `x`/`y` is the box's top-left corner.
     *
     * ⚠️ Five of the six were MEASURED off the artwork; `subject` was INVENTED.
     * Neither shipped design has a place for a subject — the name frame is the
     * only free area — so it sits inside that frame, on its own line beneath the
     * name. A reader comparing these numbers to the image will find five that
     * match a drawn box and one that matches nothing, and this is why.
     *
     * `color` names a token from `@theme` and never a hex. The one it names,
     * `certificate-ink`, is deliberately NOT redefined in the dark-theme block:
     * the artwork is a fixed light image, so a theme-reactive ink would turn
     * near-white over a cream ground and print nothing at all for half the
     * viewers — the invisible-colour family this tree has shipped four times,
     * reached from the one direction the token guard cannot see.
     *
     * @return list<array{key: string, name: string, image_url: string, is_default: bool, boxes: array<string, array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'classic',
                'name' => 'كلاسيكي',
                'image_url' => '/certificate-templates/classic.webp',
                'is_default' => true,
                'boxes' => [
                    'student' => self::text(0.290, 0.410, 0.420, 0.055, 0.042, 0.020),
                    'subject' => self::text(0.290, 0.468, 0.420, 0.028, 0.022, 0.013),
                    'teacher' => self::text(0.185, 0.768, 0.145, 0.026, 0.020, 0.012),
                    'date' => self::text(0.365, 0.768, 0.135, 0.026, 0.020, 0.012),
                    /*
                    | ⚠️ الرقمُ أصغرُ من جارَيه عمداً: «CERT-2026-C9RPWNCD» ثمانيةَ
                    | عشرَ محرفاً في صندوقٍ بعرضِ اسمِ مدرّس، فبحجمِ الصفِّ نفسِه
                    | يملأُ الصندوقَ حافّةً إلى حافّة — ويكسرُه المتصفّحُ عندَ
                    | الشَّرطةِ إلى سطرَينِ يتجاوزانِ الصندوقَ ارتفاعاً.
                    */
                    'number' => self::text(0.540, 0.768, 0.145, 0.026, 0.016, 0.010),
                    'qr' => ['x' => 0.757, 'y' => 0.695, 'w' => 0.063, 'h' => 0.089],
                ],
            ],
            [
                'key' => 'students',
                'name' => 'طلّاب',
                'image_url' => '/certificate-templates/students.webp',
                'is_default' => false,
                'boxes' => [
                    'student' => self::text(0.270, 0.410, 0.460, 0.055, 0.042, 0.020),
                    'subject' => self::text(0.270, 0.468, 0.460, 0.028, 0.022, 0.013),
                    /*
                    | ⚠️ الصناديقُ الثلاثةُ **مركزةٌ على إطاراتِها بالقياس**، لا
                    | بالنظر. أُخِذتْ حدودُ الأقراصِ الثلاثةِ من بكسلاتِ الصورةِ
                    | نفسِها (مسحٌ عموديٌّ للحدِّ الذهبيّ): مراكزُها الأفقيّةُ
                    | 0.2884 و0.4494 و0.6066، وباطنُها العموديُّ 0.7673…0.8043
                    | فمركزُه 0.786.
                    |
                    | وكانتْ `y` هي 0.786 نفسَها — أي **أعلى الصندوقِ عندَ منتصفِ
                    | الإطار**، فيتدلّى نصفُه تحتَ القرصِ كلِّه؛ ومركزُ صندوقِ
                    | المدرّسِ كانَ 0.2775 بدلَ 0.2884 أي إزاحةً بستّةَ عشرَ بكسلاً
                    | يساراً. الشكلُ الصحيحُ أن تُشتَقَّ `y` من المركز:
                    | `y = 0.786 − h/2`.
                    */
                    'teacher' => self::text(0.219, 0.772, 0.138, 0.028, 0.020, 0.012),
                    'date' => self::text(0.386, 0.772, 0.126, 0.028, 0.020, 0.012),
                    'number' => self::text(0.540, 0.772, 0.133, 0.028, 0.016, 0.010),
                    'qr' => ['x' => 0.727, 'y' => 0.706, 'w' => 0.068, 'h' => 0.096],
                ],
            ],
        ];
    }

    /** @return array{key: string, name: string, image_url: string, is_default: bool, boxes: array<string, array<string, mixed>>} */
    public static function default(): array
    {
        foreach (self::all() as $template) {
            if ($template['is_default']) {
                return $template;
            }
        }

        // Unreachable while the test below holds; a design is better than a crash.
        return self::all()[0];
    }

    /** @return array{key: string, name: string, image_url: string, is_default: bool, boxes: array<string, array<string, mixed>>}|null */
    public static function find(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        foreach (self::all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /** The six keys a `field_boxes` object may carry, and no others. */
    public const FIELDS = ['student', 'subject', 'teacher', 'date', 'number', 'qr'];

    /** @return array<string, mixed> */
    private static function text(float $x, float $y, float $w, float $h, float $max, float $min): array
    {
        return [
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'align' => 'center',
            // Font sizes are fractions of the image HEIGHT, so they scale with it.
            'max_font' => $max,
            'min_font' => $min,
            'color' => 'certificate-ink',
        ];
    }
}
