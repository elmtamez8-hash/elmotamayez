<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
| `COUNT(read_at) as reads` is fine on SQLite and a syntax error on MySQL,
| where READS is reserved — so saving an announcement answered 500 on
| production (2026-09-24) while every test was green. This scans raw SQL in
| app/ for an alias that MySQL 8 reserves. The list is the reserved words a
| column alias plausibly reaches for, not all of them.
*/

it('names no raw SQL alias with a MySQL reserved word', function (): void {
    $reserved = 'reads|read|order|group|groups|key|keys|rank|rows|row|range|lead|lag|window|interval|'
        .'condition|desc|asc|limit|option|release|index|match|references|schema|check|column|'
        .'cume_dist|dense_rank|first_value|last_value|nth_value|ntile|percent_rank|row_number|'
        .'recursive|system|of|over|partition|virtual|stored|generated|usage|change';

    $hits = [];
    $files = 0;

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $files++;

        // Comments stripped: prose says «as stored,» and is not SQL.
        $code = '';
        foreach (token_get_all($file->getContents()) as $token) {
            $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
        }

        if (preg_match_all('/\bas\s+('.$reserved.')\b\s*[,\'")]/i', $code, $m) > 0) {
            $hits[] = $file->getRelativePathname().': '.implode(', ', $m[1]);
        }
    }

    expect($files)->toBeGreaterThan(100)
        ->and($hits)->toBe([]);
});
