<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Providers\ManualTransferProvider;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Payments\Support\PaymentReturnUrl;
use App\Modules\Tenancy\Support\Roles;
use Tests\Support\FakePaymentProvider;

/*
| Where a gateway sends the payer back to — spec 007's missing half.
|
| ⚠️ `/billing/pay/return` SHIPPED WITH NOTHING POINTING AT IT, ON EITHER SIDE.
| The screen reads `?transaction=`, polls the API for the settled answer and
| renders three real states — and no link, no config and no builder anywhere
| constructed its URL. `ChargeIntent::redirectUrl` sends the payer TO the
| gateway; there was no field for the other direction at all. So the day a real
| Qatari gateway is integrated, whoever wires it would have found a finished
| screen with no inbound path and invented a URL nobody registered.
|
| ⚠️ AND THE UUID IS THE WHOLE ASSERTION. The URL is built one line BEFORE the
| transaction row exists, because the gateway is told where to return the payer
| at the moment it is asked for a charge. If `HasUuid` overwrote the minted value
| — it does not; it generates only when the attribute is null — the URL would
| name a transaction that never existed, and nothing would notice, because
| nothing follows that URL today.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->provider = new FakePaymentProvider;
    $this->provider->identifier = 'gateway';

    app()->instance(FakePaymentProvider::class, $this->provider);
    app()->tag([FakePaymentProvider::class], 'payment.providers');
    app()->forgetInstance(PaymentProviderRegistry::class);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'pending',
    ]);
});

it('hands the gateway a return url naming the transaction it just created', function (): void {
    app(InitiatePayment::class)->handle($this->order, $this->provider);

    $transaction = PaymentTransaction::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    expect($this->provider->returnUrls)->toHaveCount(1);

    // The persisted uuid, not merely «a uuid». A minted value the model then
    // replaced would still produce a well-formed URL — pointing at nothing.
    expect($this->provider->returnUrls[0])
        ->toContain('/billing/pay/return')
        ->toContain('transaction='.$transaction->uuid);
});

it('gives each attempt its own url, because each attempt is its own row', function (): void {
    app(InitiatePayment::class)->handle($this->order, $this->provider);
    app(InitiatePayment::class)->handle($this->order, $this->provider);

    // Retry is the ordinary case: a student abandons a bank page and comes back.
    // A URL keyed on the ORDER would report on whichever attempt a query
    // returned first, rather than on the one the payer just made.
    expect($this->provider->returnUrls[0])->not->toBe($this->provider->returnUrls[1]);

    $uuids = PaymentTransaction::query()
        ->withoutWorkspaceScope()
        ->orderBy('id')
        ->pluck('uuid')
        ->all();

    expect($this->provider->returnUrls[0])->toContain('transaction='.$uuids[0])
        ->and($this->provider->returnUrls[1])->toContain('transaction='.$uuids[1]);
});

it('builds the url from the app origin, never from the api origin', function (): void {
    config(['payments.return_url_base' => 'https://app.example.test/']);

    $url = app(PaymentReturnUrl::class)->for('a-uuid');

    // ⚠️ THE TRAILING SLASH IS TRIMMED. `FRONTEND_URL=https://app.test/` is an
    // ordinary thing to write in an env file, and the doubled slash it would
    // otherwise produce is a 404 on some hosts and a silent redirect on others
    // — discovered by a payer mid-payment, on a screen nobody can reach twice.
    expect($url)->toBe('https://app.example.test/billing/pay/return?transaction=a-uuid');
});

it('still starts a manual transfer, which has nowhere to return from', function (): void {
    // A bank transfer has no payment page — the payer never left, so there is
    // nothing to come back from. The parameter is taken and ignored, and the
    // charge must not care.
    $intent = app(ManualTransferProvider::class)->createCharge($this->order, 'https://app.test/x');

    expect($intent->redirectUrl)->toBeNull()
        ->and($intent->instructions)->not->toBeNull();
});
