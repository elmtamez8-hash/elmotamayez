<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\CreditPackageResource\Pages;

use App\Modules\Payments\Filament\Resources\CreditPackageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCreditPackage extends CreateRecord
{
    protected static string $resource = CreditPackageResource::class;
}
