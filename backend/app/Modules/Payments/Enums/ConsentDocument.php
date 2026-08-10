<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * The documents a person can be asked to accept.
 *
 * ⚠️ TWO CASES, AND THE SECOND ONE IS THE REQUIREMENT. FR-050 forbids either
 * consent standing in for the other, and a single-valued enum makes that
 * impossible to express — the column would be a constant, every reader would ask
 * the same question, and the rule would read as implemented while nothing checked
 * it. Every reader names the document it is asking about.
 *
 * `DataProcessing` is stored and read here because the table and the registry are
 * platform-owned and general; what the text says, and the erasure rules around
 * it, belong to spec 013.
 */
enum ConsentDocument: string
{
    case DeferredPaymentTerms = 'deferred_payment_terms';
    case DataProcessing = 'data_processing';

    public function label(): string
    {
        return match ($this) {
            self::DeferredPaymentTerms => 'شروط الدفع المؤجَّل',
            self::DataProcessing => 'معالجة البيانات الشخصية',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
