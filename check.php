<?php

declare(strict_types=1);

/**
 * Cron entrypoint: check all stored expatriates.com ads and update ads.json.
 *
 * Crontab example (every 10 minutes):
 *   every 10 min: /usr/bin/php /path/to/expat-ads/check.php >> /path/to/expat-ads/data/cron.log 2>&1
 */

require __DIR__ . '/lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$ads = load_ads();
$total = count($ads);

if ($total === 0) {
    echo '[' . gmdate('c') . "] No ads to check.\n";
    exit(0);
}

echo '[' . gmdate('c') . "] Checking {$total} ads...\n";

$summary = ['active' => 0, 'removed' => 0, 'unknown' => 0];

foreach ($ads as $i => $ad) {
    $ads[$i] = refresh_ad($ad);
    $status = (string) $ads[$i]['status'];
    if (!isset($summary[$status])) {
        $summary[$status] = 0;
    }
    $summary[$status]++;

    echo sprintf(
        "  - %s => %s (%s)\n",
        $ads[$i]['url'],
        $status,
        (string) ($ads[$i]['note'] ?? '')
    );

    // Pause between ads (Chrome + Cloudflare needs breathing room).
    if ($i < $total - 1) {
        sleep(2);
    }
}

save_ads($ads);

echo sprintf(
    "[%s] Done. active=%d removed=%d unknown=%d\n",
    gmdate('c'),
    $summary['active'],
    $summary['removed'],
    $summary['unknown']
);

exit(0);
