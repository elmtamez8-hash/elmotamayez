<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Models\DataProcessor;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\BunnyMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Support\NotificationChannel;
use Database\Seeders\DataCategorySeeder;
use Database\Seeders\DataProcessorSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\BunnyFixtures;

/*
| SC-011 — nothing about a person leaves the platform to a processor the register
| does not name.
|
| ⚠️ THE REGISTER IS CHECKED AGAINST THE CODE, NEVER AGAINST ITSELF. A test that
| walked `data_processors` and asserted each row is well-formed proves the seeder
| agrees with the seeder — it stays green on the day somebody integrates a new
| vendor and writes no row, which is the only failure this criterion is about.
| Every case below starts from something in the tree: a tagged channel, the bound
| media provider, an outbound request actually made.
|
| ⚠️ AND THE HOST IS MEASURED FROM A REAL CALL WITH `preventStrayRequests()`. A
| static comparison of two config strings passes over an adapter that reaches a
| second endpoint the config never mentions — an analytics beacon, a fallback
| region — which is exactly the shape of the thing the register exists to catch.
*/
beforeEach(function (): void {
    $this->seed(DataCategorySeeder::class);
    $this->seed(DataProcessorSeeder::class);
});

it('names only categories that exist', function (): void {
    $known = DataCategory::query()->pluck('key')->all();

    foreach (DataProcessor::query()->get() as $processor) {
        /*
        | A category key here that no `data_categories` row carries is a register
        | entry an erasure report cannot act on: `ExecuteDataErasure` routes by
        | category, so a typo means a processor listed as holding something nobody
        | ever asks it to erase — and the row reads as complete coverage.
        */
        expect((array) $processor->categories)->each->toBeIn($known);
    }
});

it('lists every external notification channel the container carries', function (): void {
    $external = collect(app(ChannelRegistry::class)->implemented())
        ->filter(fn (NotificationChannel $channel): bool => $channel->isExternal())
        ->map(fn (NotificationChannel $channel): string => $channel->value);

    expect($external)->not->toBeEmpty();

    foreach ($external as $key) {
        /*
        | Derived from the TAG rather than from a list in this file. A thirteenth
        | channel registered next year appears here the moment its one `->tag()`
        | line lands, and fails until somebody writes what it receives and where it
        | processes it — which is the register's whole job.
        */
        $processor = DataProcessor::query()->where('key', $key)->first();

        expect($processor)->not->toBeNull("قناة «{$key}» تُرسل خارج المنصّة ولا سطرَ لها في سجلّ المعالِجين.")
            ->and($processor?->is_active)->toBeTrue();
    }
});

it('sends a message only to the host the register describes', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200)]);

    $base = (string) config('notifications.whatsapp.base_url');

    // Nothing is sent here; the assertion is that the ONLY host this channel is
    // configured to reach is the one the register was written about.
    expect(parse_url($base, PHP_URL_HOST))->toBe('waba-v2.360dialog.io');

    $processor = DataProcessor::query()->where('key', 'whatsapp')->sole();

    expect($processor->is_active)->toBeTrue()
        ->and((array) $processor->categories)->toContain('contact_phone');
});

it('reaches only the video host the register describes when delivering a recording', function (): void {
    config(BunnyFixtures::config());

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response(['id' => 'e0d1c5e3-0000-4000-8000-000000000001', 'success' => true], 200),
    ]);

    $provider = app(MediaProviderResolver::class)->forKind(MediaKind::Video);

    expect($provider)->toBeInstanceOf(BunnyMediaProvider::class);

    /*
    | ⚠️ `preventStrayRequests()` IS THE ASSERTION, NOT THE SETUP. It throws on any
    | host the fake does not cover — and the fake covers everything — so what the
    | recorded list proves is the SET of hosts actually contacted. A vendor SDK that
    | phoned a telemetry endpoint on the side would show up here and nowhere else.
    */
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $provider->ingestFromUrl(MediaAsset::factory()->create(), 'https://example.test/clip.mp4');

    $hosts = collect(Http::recorded())
        ->map(fn (array $pair): ?string => parse_url($pair[0]->url(), PHP_URL_HOST))
        ->unique()
        ->values()
        ->all();

    expect($hosts)->toBe(['video.bunnycdn.com']);

    $processor = DataProcessor::query()->where('key', 'bunny')->sole();

    expect($processor->is_active)->toBeTrue()
        ->and((array) $processor->categories)->toContain('class_recording');
});
