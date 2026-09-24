<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/*
| Three pieces of English and raw keys that reached teachers on production
| (2026-09-24): the «(and N more errors)» tail on every multi-error 422, a
| field printed by its key («يجب ألا تقل قيمة capacity عن 1»), and `after:now`
| printing the word «now».
*/

function arabicSummary(array $data, array $rules): string
{
    try {
        Validator::make($data, $rules)->validate();
    } catch (ValidationException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('expected the data to fail validation');
}

it('summarises one extra error in Arabic', function (): void {
    $message = arabicSummary(['a' => null, 'b' => null], ['a' => 'required', 'b' => 'required']);

    expect($message)->toContain('(وخطأ آخر)')
        ->and($message)->not->toContain('more error');
});

it('summarises several extra errors in Arabic', function (): void {
    $message = arabicSummary(
        ['a' => null, 'b' => null, 'c' => null],
        ['a' => 'required', 'b' => 'required', 'c' => 'required'],
    );

    expect($message)->toContain('(وأخطاء أخرى عددها 2)')
        ->and($message)->not->toContain('more errors');
});

it('names a field by its Arabic attribute, not its key', function (): void {
    $message = arabicSummary(['capacity' => 0], ['capacity' => 'integer|min:1']);

    expect($message)->toContain('السعة')
        ->and($message)->not->toContain('capacity');
});

it('says «الآن» instead of «now» on every after:now field', function (string $field): void {
    $message = arabicSummary([$field => now()->subDay()->toDateTimeString()], [$field => 'date|after:now']);

    expect($message)->toContain('بعد الآن')
        ->and($message)->not->toContain('now');
})->with(['starts_at', 'until', 'expires_at']);
