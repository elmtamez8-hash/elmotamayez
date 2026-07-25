<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Listeners\CreateEnrollmentFromOrder;
use App\Modules\Payments\Providers\ManualTransferProvider;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class PaymentsServiceProvider extends Module
{
    protected string $name = 'Payments';

    public function register(): void
    {
        parent::register();

        // Bind the manual provider as the default implementation.
        $this->app->bind(PaymentProviderInterface::class, ManualTransferProvider::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(PaymentApproved::class, CreateEnrollmentFromOrder::class);
    }
}
