<?php

declare(strict_types=1);

/**
 * WorkspaceContext is an application-wide singleton that caches its answer, so a
 * set() inside a queued job leaks that workspace into whatever the same worker
 * picks up next — a silent cross-tenant read with no failing test anywhere else.
 *
 * Same guard as the marketplace's trust-score job.
 */
it('never sets the workspace context inside a media job', function (): void {
    $jobs = glob(app_path('Modules/Media/Jobs/*.php')) ?: [];

    expect($jobs)->not->toBeEmpty();

    foreach ($jobs as $job) {
        $contents = (string) file_get_contents($job);

        expect($contents)->not->toContain(
            'WorkspaceContext::set(',
            basename($job).' must use forWorkspace(), never set().',
        );
        expect($contents)->not->toContain('->set($workspace)');
    }
});
