<?php

declare(strict_types=1);

namespace App\Modules\CMS\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tell the search engines an article was published or changed (011 · FR-037).
 *
 * ⚠️ THIS IS INDEXNOW, NOT A SITEMAP PING, AND THE OBVIOUS IMPLEMENTATION WOULD
 * HAVE DONE NOTHING. `GET /ping?sitemap=` was removed by Google in January 2024
 * and Bing had already routed its own to IndexNow — a job posting to either
 * address today gets a 404 or a redirect, logs a warning nobody reads, and
 * satisfies FR-037 on paper while notifying no search engine at all. The request
 * shape below is pinned by `PingSearchEnginesTest` against the published protocol
 * (indexnow.org/documentation): POST, `application/json; charset=utf-8`,
 * `{host, key, keyLocation?, urlList}`, up to 10,000 URLs.
 *
 * ⚠️ THE PATHS ARE STRINGS, NOT MODELS. A job payload serialises its constructor
 * arguments into Redis and, on failure, into `failed_jobs.payload` — a table
 * nothing sweeps. Every job in this tree takes an identifier and re-reads; this
 * one does not even need to re-read, because a URL is the whole message.
 *
 * ⚠️ AND AN UNCONFIGURED DEPLOYMENT IS A SILENT NO-OP, deliberately. The key
 * proves ownership by being served as a file at the site root, which is an
 * operator step; submitting without it earns a `403` on every publish for as long
 * as it is missing. Better to send nothing than to fill the log with a refusal
 * caused by a file that was never put there.
 */
class PingSearchEnginesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Two tries: the engines are advisory and a lost ping costs a crawl delay. */
    public int $tries = 2;

    public int $backoff = 60;

    /** @param list<string> $paths site-relative paths, e.g. `/blog/خطة-المراجعة` */
    public function __construct(private readonly array $paths)
    {
        // `maintenance`, named in both `defaults` and `environments` in
        // config/horizon.php — a queue present in only the first is a queue whose
        // jobs enqueue and are never drained, silently.
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $key = (string) config('cms.indexnow.key', '');

        if ($key === '' || $this->paths === []) {
            return;
        }

        $site = (string) config('cms.site_url');
        $host = (string) parse_url($site, PHP_URL_HOST);

        if ($host === '') {
            Log::warning('IndexNow skipped: cms.site_url has no host.');

            return;
        }

        $payload = [
            'host' => $host,
            'key' => $key,
            'urlList' => array_map(
                // `rawurlencode` per segment, never on the whole path: the slugs
                // are Arabic, and an unencoded one in a JSON body is a URL the
                // engine cannot match against what it crawled.
                fn (string $path): string => $site.$this->encodePath($path),
                array_slice($this->paths, 0, 10_000),
            ),
        ];

        $location = config('cms.indexnow.key_location');

        if (is_string($location) && $location !== '') {
            // Optional by the protocol, and omitted rather than sent empty — the
            // same lesson `videos/fetch` cost us, where `headers: []` encoded as a
            // JSON array where an object was expected and 400'd every recording.
            $payload['keyLocation'] = $location;
        }

        try {
            $response = Http::asJson()
                ->withHeaders(['Content-Type' => 'application/json; charset=utf-8'])
                ->timeout(10)
                ->post((string) config('cms.indexnow.endpoint'), $payload);
        } catch (Throwable $exception) {
            // ⚠️ THE CLASS, NEVER THE MESSAGE. A `QueryException`'s message
            // carries its bindings, and an operational log leaves the building
            // (FR-041). Nothing is lost: the job rethrows, and the full trace
            // lands in `failed_jobs`, in our own database.
            Log::warning('IndexNow submission failed: '.$exception::class);

            throw $exception;
        }

        // 200 accepted · 202 accepted with key validation pending. Both are done.
        if ($response->successful()) {
            return;
        }

        // 403 (key not found in the file) and 422 (URLs outside the host) are
        // configuration, not weather: retrying spends the rate budget on a request
        // that will be refused identically. 429 and 5xx are worth the second try.
        if (in_array($response->status(), [403, 422], true)) {
            Log::error('IndexNow refused the submission: HTTP '.$response->status().' — check INDEXNOW_KEY and the key file.');

            return;
        }

        Log::warning('IndexNow answered HTTP '.$response->status().'.');

        $this->release($this->backoff);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $path),
        ));
    }
}
