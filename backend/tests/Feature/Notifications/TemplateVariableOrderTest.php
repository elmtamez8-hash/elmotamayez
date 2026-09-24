<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;

/*
| Every template's `variables` against the placeholders its own text reads.
|
| ⚠️ WHATSAPP ROWS: THE LIST IS THE BODY'S PLACEHOLDERS, IN THE ORDER THEY FIRST
| APPEAR — nothing more, nothing less. `WhatsAppChannel::post()` sends ONE
| component, `body`, whose parameters are `variables` in their declared order, and
| the approved template at the provider numbers its placeholders {{1}}, {{2}}… in
| reading order. So a list whose order differs from the body puts the months where
| the credits belong in a message to a parent, with no error anywhere; and a
| variable that lives only in the TITLE is a parameter the body has no slot for,
| which the provider refuses outright. The title never travels — it is not a
| header component — so it plays no part in the order.
|
| ⚠️ IN-APP ROWS: THE SET, NOT THE ORDER. The renderer substitutes by NAME, so order
| means nothing there — but a placeholder missing from the list is not required,
| and when the caller does not supply it the reader sees «{{ course_title }}» or an
| empty «» instead of a refusal (FR-037). That is how `certificate_issued` shipped.
|
| Measured on the seeded rows — the seeder is what every database is born from, and
| the backfill migrations call its `seedMissing()`.
*/

/** @return list<string> */
function placeholdersIn(string $text): array
{
    preg_match_all('/\{\{\s*(\w+)\s*\}\}/u', $text, $matches);

    return array_values(array_unique($matches[1]));
}

it('lists every whatsapp template variable in the order its body reads them', function (): void {
    $rows = MessageTemplate::query()->where('channel', NotificationChannel::WhatsApp->value)->get();

    // A scan over nothing proves nothing.
    expect($rows->count())->toBeGreaterThan(10);

    $wrong = [];

    foreach ($rows as $row) {
        $expected = placeholdersIn((string) $row->body);

        if ($row->variables !== $expected) {
            $wrong[$row->type] = ['variables' => $row->variables, 'body order' => $expected];
        }
    }

    expect($wrong)->toBe([]);
});

it('requires every placeholder an in-app template reads', function (): void {
    $rows = MessageTemplate::query()->where('channel', NotificationChannel::InApp->value)->get();

    expect($rows->count())->toBeGreaterThan(40);

    $wrong = [];

    foreach ($rows as $row) {
        $read = placeholdersIn($row->title.' '.$row->body);
        $declared = $row->variables ?? [];

        sort($read);
        sort($declared);

        if ($read !== $declared) {
            $wrong[$row->type] = ['variables' => $declared, 'placeholders' => $read];
        }
    }

    expect($wrong)->toBe([]);
});

function runVariableOrderMigration(): void
{
    $migration = require base_path(
        'app/Modules/Notifications/Database/Migrations/2026_09_24_000200_order_template_variables_by_body.php'
    );

    $migration->up();
}

it('carries an existing database forward to the order the seeder writes', function (): void {
    $row = MessageTemplate::query()
        ->where('type', 'credit_balance_dormant')
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->firstOrFail();

    $fresh = $row->variables;
    $body = $row->body;

    // A database seeded before the fix.
    $row->forceFill(['variables' => ['course', 'credits', 'months']])->save();

    runVariableOrderMigration();

    $row->refresh();

    expect($row->variables)->toBe($fresh)
        ->and($row->variables)->toBe(['course', 'months', 'credits'])
        // Only the list moves — never the words.
        ->and($row->body)->toBe($body);
});

it('adds the course title certificate_issued always printed', function (): void {
    $row = MessageTemplate::query()
        ->where('type', 'certificate_issued')
        ->where('channel', NotificationChannel::InApp->value)
        ->firstOrFail();

    $row->forceFill(['variables' => ['name', 'certificate_number']])->save();

    runVariableOrderMigration();

    expect($row->refresh()->variables)->toBe(['name', 'certificate_number', 'course_title']);
});

it('leaves a list an operator already changed as they left it', function (): void {
    $row = MessageTemplate::query()
        ->where('type', 'session_report')
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->firstOrFail();

    $row->forceFill(['variables' => ['title', 'student_name', 'status', 'minutes']])->save();

    runVariableOrderMigration();

    expect($row->refresh()->variables)->toBe(['title', 'student_name', 'status', 'minutes']);
});
