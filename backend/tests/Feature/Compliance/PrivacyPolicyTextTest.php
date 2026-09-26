<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\PlatformSettings;

/*
| The published policy quotes FACTS the operator owns, filled at read time.
|
| ⚠️ «خلال المدّة المعلَنة هناك» shipped as the whole answer to «how long until
| you reply» — a promise pointing at a number the text never stated. The number
| lives in `platform_settings`, so the text reads it rather than copying it.
*/

it('states the reply deadline from the settings, in the reader\'s digits', function (): void {
    PlatformSettings::set('compliance.request_due_days', 21);

    $html = (string) $this->getJson('/api/v1/privacy/policy')->assertOk()->json('body_html');

    expect($html)->toContain('٢١ يوماً')
        ->not->toContain('{{')
        // The drafting note and the raw endpoint were addressed to us, not the reader.
        ->not->toContain('POST /api')
        ->not->toContain('تحرّره الجهةُ القانونية');
});

it('drops the support line whole when no number is set', function (): void {
    PlatformSettings::set('platform.support_whatsapp', '');

    $html = (string) $this->getJson('/api/v1/privacy/policy')->json('body_html');

    expect($html)->not->toContain('واتساب الدعم');
});

it('prints the support number when one is set', function (): void {
    PlatformSettings::set('platform.support_whatsapp', '97455501234');

    $html = (string) $this->getJson('/api/v1/privacy/policy')->json('body_html');

    expect($html)->toContain('واتساب الدعم: +97455501234');
});

it('travels with the version a signature must name', function (): void {
    expect($this->getJson('/api/v1/privacy/policy')->json('version'))
        ->toBe('1.1');
});
