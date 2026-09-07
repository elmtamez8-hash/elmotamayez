<?php

declare(strict_types=1);

use App\Modules\Courses\DTOs\CreateCourseDTO;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| ⚠️ يُعلِنُ حاجتَه إلى قاعدةِ بياناتٍ بنفسِه — انظرْ `CostPlusPricingTest` للسبب.
|
| وهو يحتاجُها بلا التباس: سؤالُه هو «هل يعودُ المبلغُ عدداً صحيحاً بعدَ رحلةٍ في
| كلِّ جدولٍ يمرُّ به المال؟»، وهو سؤالٌ لا يُطرَحُ إلّا على جدولٍ حقيقيّ.
*/
uses(RefreshDatabase::class);

/*
| NFR-007 — money is an integer, and it stays one on the way back out.
|
| ⚠️ THE POINT IS THE TYPE, NOT THE VALUE. `decimal:2` returns a STRING, so
| `4999 === $model->price_minor` was false for every row on this platform before
| 007 — and every sum passed through a float on its way to being right by
| accident. A test asserting only equality would pass against the old cast; these
| assert identity, which the old cast could not satisfy.
*/

it('reads back exactly what was written, as an int', function (): void {
    $course = Course::factory()->create(['price_minor' => 4999]);

    expect($course->fresh()->price_minor)->toBe(4999);
});

it('keeps the two decimal places a float would have lost', function (): void {
    // 0.1 + 0.2 in fils. In riyals this is the canonical float defect; as
    // integers there is nothing to lose.
    $course = Course::factory()->create(['price_minor' => 10 + 20]);

    expect($course->fresh()->price_minor)->toBe(30);
});

it('carries the price through every table money passes on the way to a payment', function (): void {
    $course = Course::factory()->create(['price_minor' => 75000, 'currency' => 'QAR']);

    // Created directly: orders and transactions have no factories, and three
    // new ones for one assertion is more fixture than test.
    $order = Order::create([
        'workspace_id' => $course->workspace_id,
        'user_id' => 1,
        'course_id' => $course->getKey(),
        'amount_minor' => $course->price_minor,
        'currency' => $course->currency,
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $transaction = PaymentTransaction::create([
        'workspace_id' => $order->workspace_id,
        'order_id' => $order->getKey(),
        'provider' => 'manual',
        'amount_minor' => $order->amount_minor,
        'currency' => $order->currency,
        'status' => 'captured',
        'reference' => 'unit-'.$order->getKey(),
    ]);

    expect($order->fresh()->amount_minor)->toBe(75000)
        ->and($transaction->fresh()->amount_minor)->toBe(75000);
});

it('treats a zero price as free without asking a float', function (): void {
    $free = Course::factory()->create(['price_minor' => 0]);
    $paid = Course::factory()->create(['price_minor' => 1]);

    // One fils is not free. `(float) $price === 0.0` said the same thing, but
    // only because 0.01 happens to survive the cast — the comparison itself was
    // the defect.
    expect($free->isFree())->toBeTrue()
        ->and($paid->isFree())->toBeFalse();
});

it('accepts a minor-unit price through the course DTO', function (): void {
    $dto = CreateCourseDTO::fromArray(['title' => 'الرياضيات', 'price_minor' => 4999]);

    expect($dto->priceMinor)->toBe(4999)
        // The currency default moved with the money: a Qatari product defaulting
        // to USD was a silent fault, not a placeholder.
        ->and($dto->currency)->toBe('QAR');
});

it('prices a product in minor units too', function (): void {
    $product = Product::create([
        'workspace_id' => 1,
        'name' => 'دفتر تمارين',
        'price_minor' => 12345,
        'currency' => 'QAR',
    ]);

    expect($product->fresh()->price_minor)->toBe(12345);
});
