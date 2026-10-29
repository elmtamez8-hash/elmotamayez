<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\ExpireUnpaidCouponOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Hourly: give back the coupon places of orders that never received a receipt
 * (owner decision 2026-10-29). See {@see ExpireUnpaidCouponOrders}.
 *
 * `WithoutOverlapping` as job middleware — `Schedule::job()->withoutOverlapping()`
 * guards only the dispatch (docs/gotchas/deploy-ops.md).
 */
class ExpireUnpaidCouponOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('coupon-order-expiry'))->expireAfter(900)];
    }

    public function handle(ExpireUnpaidCouponOrders $action): void
    {
        $action->handle();
    }
}
