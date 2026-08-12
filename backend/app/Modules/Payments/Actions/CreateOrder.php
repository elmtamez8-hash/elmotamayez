<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Models\Order;
use App\Shared\Actions\Action;

class CreateOrder extends Action
{
    public function handle(Course $course, User $user): Order
    {
        return Order::create([
            'workspace_id' => $course->workspace_id,
            'user_id' => $user->getKey(),
            'course_id' => $course->getKey(),
            'amount_minor' => $course->price_minor,
            'currency' => $course->currency,
            'provider' => 'manual',
            'status' => 'pending',
        ]);
    }
}
