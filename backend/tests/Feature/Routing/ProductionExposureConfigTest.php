<?php

declare(strict_types=1);

/*
| Two config files that answered production the way they answer a laptop.
|
| ⛔ `/docs` (Scribe) was public: `add_routes` was `true` with no middleware and
| nginx routed `/docs` to PHP, so the full endpoint map — every route, parameter
| and response shape, plus the OpenAPI and Postman exports — was one
| unauthenticated GET away.
|
| ⚠️ CORS allowed `http://localhost:3000` and `127.0.0.1:3000` WITH credentials
| in every environment, on `admin/*` and `horizon/*` as well as the API.
|
| Both are decided when the config file is evaluated (routes are registered
| once at boot, and `config:cache` bakes the answer in), so that is where they
| are asserted: the file, evaluated as a server with each APP_ENV would evaluate
| it. The nginx half is read from the file that ships, the way
| `RouteReachabilityTest` reads it.
*/

/**
 * `env()` reads `$_SERVER` first, then `$_ENV`, then `getenv()`; every variable
 * named is set in all three and put back afterwards, so nothing leaks into the
 * next test. A null value means «not set at all».
 *
 * @param  array<string, string|null>  $variables
 * @return array<string, mixed>
 */
function configFileUnder(string $file, array $variables): array
{
    $saved = [];

    foreach ($variables as $name => $value) {
        $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        if ($value !== null) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($saved as $name => [$server, $env, $put]) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($put !== false) {
                putenv($name.'='.$put);
            }
        }
    }
}

it('registers no docs route in production', function (): void {
    expect(configFileUnder('scribe.php', ['APP_ENV' => 'production'])['laravel']['add_routes'])->toBeFalse();
});

it('registers no docs route when APP_ENV is not set at all', function (): void {
    expect(configFileUnder('scribe.php', ['APP_ENV' => null])['laravel']['add_routes'])->toBeFalse();
});

it('keeps the docs for local development', function (): void {
    expect(configFileUnder('scribe.php', ['APP_ENV' => 'local'])['laravel']['add_routes'])->toBeTrue();
});

it('no longer routes /docs to PHP in the production nginx config', function (): void {
    $config = (string) file_get_contents(base_path('../docker/nginx.prod.conf'));

    expect($config)->toContain('location ^~ /api')
        ->and(preg_match('#^\s*location\s+(?:\^~\s+)?/docs#m', $config))->toBe(0);
});

it('allows no localhost origin in production', function (): void {
    $cors = configFileUnder('cors.php', ['APP_ENV' => 'production', 'FRONTEND_URL' => null]);

    expect($cors['allowed_origins'])->toBe([]);
});

it('allows exactly the configured frontend in production', function (): void {
    $cors = configFileUnder('cors.php', ['APP_ENV' => 'production', 'FRONTEND_URL' => 'https://app.example.test']);

    expect($cors['allowed_origins'])->toBe(['https://app.example.test']);
});

it('keeps the localhost origins for local development', function (): void {
    $cors = configFileUnder('cors.php', ['APP_ENV' => 'local', 'FRONTEND_URL' => 'http://localhost:3000']);

    expect($cors['allowed_origins'])->toBe(['http://localhost:3000', 'http://127.0.0.1:3000']);
});

it('answers cross-origin for the API only, never for the session panels', function (): void {
    $paths = configFileUnder('cors.php', ['APP_ENV' => 'local'])['paths'];

    expect($paths)->toContain('api/*')
        ->and($paths)->not->toContain('admin/*')
        ->and($paths)->not->toContain('horizon/*');
});
