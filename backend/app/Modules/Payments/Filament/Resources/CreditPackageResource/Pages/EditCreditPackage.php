<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\CreditPackageResource\Pages;

use App\Modules\Payments\Filament\Resources\CreditPackageResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditCreditPackage extends EditRecord
{
    protected static string $resource = CreditPackageResource::class;

    /**
     * No DeleteAction, deliberately.
     *
     * Filament puts one here by default, and it is the one button this screen
     * must not have: every purchase ever made from a package still points at its
     * row. Retiring is the toggle in the form.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
