<?php

declare(strict_types=1);

use App\Modules\Certificates\Support\CertificateTemplateRegistry;

describe('the shipped template registry', function (): void {
    it('has exactly one default', function (): void {
        $defaults = array_filter(CertificateTemplateRegistry::all(), fn (array $t): bool => $t['is_default']);

        expect($defaults)->toHaveCount(1)
            ->and(CertificateTemplateRegistry::default()['key'])
            ->toBe(CertificateTemplateRegistry::DEFAULT_KEY);
    });

    it('gives every template all six field boxes and nothing else', function (): void {
        foreach (CertificateTemplateRegistry::all() as $template) {
            expect(array_keys($template['boxes']))
                ->toEqualCanonicalizing(CertificateTemplateRegistry::FIELDS);
        }
    });

    it('keeps every box inside the image', function (): void {
        foreach (CertificateTemplateRegistry::all() as $template) {
            foreach ($template['boxes'] as $field => $box) {
                $where = "{$template['key']}.{$field}";

                expect($box['x'])->toBeGreaterThanOrEqual(0.0, $where)
                    ->and($box['y'])->toBeGreaterThanOrEqual(0.0, $where)
                    ->and($box['w'])->toBeGreaterThan(0.0, $where)
                    ->and($box['h'])->toBeGreaterThan(0.0, $where)
                    ->and($box['x'] + $box['w'])->toBeLessThanOrEqual(1.0, $where)
                    ->and($box['y'] + $box['h'])->toBeLessThanOrEqual(1.0, $where);

                if ($field !== 'qr') {
                    expect($box['min_font'])->toBeLessThanOrEqual($box['max_font'], $where)
                        ->and($box['max_font'])->toBeLessThanOrEqual($box['h'], $where);
                }
            }
        }
    });

    /*
    | ⚠️ THE ONE ASSERTION THAT NEEDS THE DISK. A misspelt path draws a
    | certificate with NO BACKGROUND and no error anywhere: the <img> fails, the
    | fallback paints a plain sheet, and everything else about the page is
    | correct. Nothing else in either suite can see it, because the file lives on
    | the other side of the monorepo from the constant that names it.
    */
    it('points every template at a file that actually exists', function (): void {
        $public = base_path('../frontend/public');

        foreach (CertificateTemplateRegistry::all() as $template) {
            expect(is_file($public.$template['image_url']))
                ->toBeTrue("missing artwork: {$template['image_url']}");
        }
    });

    it('names a colour token and never a hex', function (): void {
        foreach (CertificateTemplateRegistry::all() as $template) {
            foreach ($template['boxes'] as $field => $box) {
                if ($field === 'qr') {
                    continue;
                }

                expect($box['color'])->toMatch('/^[a-z][a-z0-9-]*$/');
            }
        }
    });
});
