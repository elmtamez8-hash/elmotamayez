<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Shared\Modules\ModulesServiceProvider;

return [
    AppServiceProvider::class,
    ModulesServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
];
