<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\ListCreditPackages;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Filament\Pages\GrantCreditSubscription;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| The grant page printed `$e->getMessage()` for ANY failure — and a
| `QueryException`'s message carries its bound values: a student's email, an
| amount, on the one line in the panel everybody reads. Only a
| `DomainException` is a sentence written for a person.
|
| ⚠️ The sentinel is ASCII on purpose: Livewire's HTML escapes nothing in it,
| so `assertDontSee` cannot pass vacuously the way an Arabic needle against an
| encoded body can.
*/
const GRANT_FAILURE_LEAK = 'SQLSTATE[23000] bound student-leak@example.com';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Storage::fake('public');

    [$this->workspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية المنح']);
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);

    $this->package = CreditPackage::query()->create([
        'name' => 'ثماني حصص',
        'credits' => 8,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->student = User::factory()->create();
    $this->actingAs(makePlatformStaff(Roles::FINANCE_ADMIN));
});

function grantFailurePurchaseThrowing(Throwable $failure): void
{
    app()->instance(PurchaseCredits::class, new class($failure) extends PurchaseCredits
    {
        public function __construct(private readonly Throwable $failure) {}

        public function handle(
            User $student,
            Course $course,
            CreditPackage $package,
            ?string $couponCode = null,
            ?User $grantedBy = null,
        ): CreditPurchase {
            throw $this->failure;
        }
    });
}

function grantFailurePricingThrowing(Throwable $failure): void
{
    app()->instance(ListCreditPackages::class, new class($failure) extends ListCreditPackages
    {
        public function __construct(private readonly Throwable $failure) {}

        public function handle(User $student, Course $course, ?User $grantedBy = null): array
        {
            throw $this->failure;
        }
    });
}

function grantFailureForm(): mixed
{
    return Livewire::test(GrantCreditSubscription::class)->fillForm([
        'student' => test()->student->getKey(),
        'course' => test()->course->getKey(),
        'package' => test()->package->getKey(),
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
    ]);
}

it('shows a generic sentence, never a database message, when the grant fails', function (): void {
    grantFailurePurchaseThrowing(new RuntimeException(GRANT_FAILURE_LEAK));

    grantFailureForm()
        ->call('grant')
        ->assertNotified(GrantCreditSubscription::GENERIC_FAILURE)
        ->assertDontSee(GRANT_FAILURE_LEAK);
});

it('still names the reason when the Action refuses with a sentence', function (): void {
    grantFailurePurchaseThrowing(new DomainException('REFUSED_FOR_A_READER'));

    grantFailureForm()
        ->call('grant')
        ->assertNotified('REFUSED_FOR_A_READER');
});

it('keeps a database message out of the price preview too', function (): void {
    grantFailurePricingThrowing(new RuntimeException(GRANT_FAILURE_LEAK));

    grantFailureForm()
        ->assertDontSee(GRANT_FAILURE_LEAK)
        ->assertSee(GrantCreditSubscription::GENERIC_FAILURE);
});

it('prints the refusal in the price preview when it is a sentence', function (): void {
    grantFailurePricingThrowing(new DomainException('NOT_PRICEABLE_FOR_A_READER'));

    grantFailureForm()->assertSee('NOT_PRICEABLE_FOR_A_READER');
});
