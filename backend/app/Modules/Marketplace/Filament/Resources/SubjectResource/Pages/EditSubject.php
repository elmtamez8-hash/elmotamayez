<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\SubjectResource\Pages;

use App\Modules\Marketplace\Filament\Resources\SubjectResource;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditSubject extends EditRecord
{
    protected static string $resource = SubjectResource::class;

    /**
     * No DeleteAction, deliberately — {@see TaxonomyPolicy::delete()}.
     *
     * Courses, student profiles and stored leaderboard keys all name this row by
     * its slug as free text, and nothing in the database defends any of them.
     * Retiring it is the toggle.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
