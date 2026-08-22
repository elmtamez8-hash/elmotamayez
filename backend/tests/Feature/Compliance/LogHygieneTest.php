<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Actions\ExecuteDataErasure;
use App\Modules\Compliance\Actions\ExecuteDataExport;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
| SC-012 · FR-041 — nothing about a person reaches a log line or a queue payload.
|
| ⚠️ EVERY NEEDLE HERE IS ASCII, AND THAT IS NOT A STYLE CHOICE. Log context and a
| queue payload are both JSON, and `json_encode` escapes non-ASCII by default — so
| an assertion that an Arabic name is absent from a serialised string is
| VACUOUSLY TRUE whatever the payload holds, because the payload carries
| `اح...`. Every exposure test in this product asserts on Arabic text;
| the ones that are safe are safe because somebody re-encoded or used an ASCII
| sentinel. This file uses sentinels.
|
| ⚠️ AND THE LOG IS CAPTURED THROUGH `MessageLogged` RATHER THAN BY READING A FILE.
| The test environment writes nowhere, so a file-based assertion passes over an
| implementation that logs the person's whole record — it would be asserting that
| an empty file contains no names.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$workspace] = $this->createWorkspaceWithOwner();

    $this->student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $this->student->forceFill([
        'first_name' => 'LEAKCANARYNAME',
        'last_name' => 'LEAKCANARYFAMILY',
        'email' => 'leakcanary@example.org',
        'phone' => '+97455512399',
    ])->save();

    $this->needles = ['LEAKCANARYNAME', 'LEAKCANARYFAMILY', 'leakcanary@example.org', '+97455512399'];
});

/** Everything written to the log while `$work` runs, message and context together. */
function capturedLog(callable $work): string
{
    $lines = [];

    Log::listen(function (MessageLogged $logged) use (&$lines): void {
        $lines[] = $logged->message.' '.json_encode($logged->context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $work();

    return implode("\n", $lines);
}

it('writes no personal detail to the log while fulfilling a request', function (): void {
    $request = app(CreateDataRequest::class)
        ->handle($this->student, (string) $this->student->uuid, DataRequestType::Export);

    $written = capturedLog(function () use ($request): void {
        (new FulfilDataRequestJob((int) $request->getKey()))->handle(
            app(ExecuteDataExport::class),
            app(ExecuteDataErasure::class),
        );
    });

    foreach ($this->needles as $needle) {
        expect($written)->not->toContain($needle);
    }
});

it('keeps a failure message out of the log even when the exception carries one', function (): void {
    $request = app(CreateDataRequest::class)
        ->handle($this->student, (string) $this->student->uuid, DataRequestType::Export);

    /*
    | ⚠️ THE EXCEPTION MESSAGE IS THE LEAK, AND A `QueryException` IS THE COMMON
    | CASE RATHER THAN AN EXOTIC ONE: Laravel interpolates the BINDINGS into its
    | message, so a failing query anywhere in the export walk carries whatever it
    | was searching for — an address, a phone number — into a line that ships
    | straight to a monitoring vendor. The full trace still reaches
    | `failed_jobs.exception`, which stays in our own database; what must not
    | travel is the operational log.
    */
    $this->mock(ExecuteDataExport::class, function ($mock): void {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException(
            'SQLSTATE[HY000]: General error (SQL: select * from users where email = leakcanary@example.org)',
        ));
    });

    $written = capturedLog(function () use ($request): void {
        try {
            (new FulfilDataRequestJob((int) $request->getKey()))->handle(
                app(ExecuteDataExport::class),
                app(ExecuteDataErasure::class),
            );
        } catch (RuntimeException) {
            // Rethrown on purpose so the queue records the failure; the assertion
            // is about what was LOGGED on the way past.
        }
    });

    expect($written)->not->toContain('leakcanary@example.org')
        // …and the line still leads somewhere: the request id and the class of the
        // failure are what an operator needs to find the row and the trace.
        ->and($written)->toContain('compliance.request.failed')
        ->and($written)->toContain((string) $request->getKey());
});

it('carries nothing but ids in a queued job payload', function (): void {
    Queue::fake();

    $request = app(CreateDataRequest::class)
        ->handle($this->student, (string) $this->student->uuid, DataRequestType::Erasure);

    FulfilDataRequestJob::dispatch((int) $request->getKey());

    $payloads = collect(Queue::pushed(FulfilDataRequestJob::class))
        ->map(fn (object $job): string => serialize($job))
        ->implode("\n");

    expect($payloads)->not->toBeEmpty();

    /*
    | ⚠️ THIS IS `failed_jobs.payload` MEASURED AT ITS SOURCE. That column is the
    | serialised job inside a JSON envelope, and rows in it OUTLIVE the erasure
    | they failed to perform — a job constructed with a `User` rather than a
    | `user_id` writes that person's name, email and phone into a table no erasure
    | walks, and leaves it there after the account is gone.
    */
    foreach ($this->needles as $needle) {
        expect($payloads)->not->toContain($needle);
    }
});
