<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Data\PackagePrice;
use App\Modules\Payments\Models\CreditPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One package, priced for one course — as a SINGLE TOTAL (FR-021ج).
 *
 * ⚠️ NOT ONE COMPONENT OF THE PRICE APPEARS HERE, and their absence is the
 * guard, not their hiding. The teacher's approved rate is the input to the
 * total; a payload carrying the breakdown would publish what the platform pays
 * that teacher to every student who opens the network tab, and — since the other
 * two components are platform constants — to every other teacher through them.
 *
 * The four components exist, frozen, on `credit_purchases`, where spec 015's
 * books read them. They travel to nobody's browser.
 *
 * @property-read array{package: CreditPackage, price: PackagePrice} $resource
 */
class CreditPackageOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CreditPackage $package */
        $package = $this->resource['package'];
        /** @var PackagePrice $price */
        $price = $this->resource['price'];

        return [
            'uuid' => $package->uuid,
            'name' => $package->name,
            'credits' => $price->credits,
            'session_type' => $package->session_type->value,
            'validity_days' => $package->validity_days,
            // Minor units, unformatted. The API never sends pre-formatted money:
            // a formatted string is a number the client has to parse back before
            // it can add anything up.
            'total_minor' => $price->totalMinor,
            'currency' => $price->currency,
        ];
    }
}
