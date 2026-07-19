<?php

declare(strict_types=1);

const ADS_FILE = __DIR__ . '/data/ads.json';
const BROWSER_FETCH_JS = __DIR__ . '/browser-fetch.js';

/**
 * @return list<array{id:string,url:string,status:string,checked_at:?string,note:?string,created_at:string}>
 */
function load_ads(): array
{
    if (!is_file(ADS_FILE)) {
        return [];
    }

    $raw = file_get_contents(ADS_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    // Newest first (latest added URL at the top).
    usort($data, static function (array $a, array $b): int {
        $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;

        return $bTime <=> $aTime;
    });

    return array_values($data);
}

/**
 * @param list<array<string,mixed>> $ads
 */
function save_ads(array $ads): void
{
    $dir = dirname(ADS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode(array_values($ads), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode ads JSON.');
    }

    $tmp = ADS_FILE . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write ads file.');
    }

    if (!rename($tmp, ADS_FILE)) {
        throw new RuntimeException('Failed to save ads file.');
    }
}

function find_ad_index(array $ads, string $id): int
{
    foreach ($ads as $i => $ad) {
        if (($ad['id'] ?? '') === $id) {
            return $i;
        }
    }

    return -1;
}

function normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    return $url;
}

function is_valid_expat_url(string $url): bool
{
    return (bool) preg_match(
        '#^https?://(www\.)?expatriates\.com/cls/\d+(\.html)?/?$#i',
        $url
    );
}

function is_cloudflare_html(string $html, int $httpCode = 0): bool
{
    $lower = strtolower($html);

    return $httpCode === 403
        || str_contains($lower, 'just a moment...')
        || str_contains($lower, 'performing security verification')
        || str_contains($lower, 'cf-mitigated')
        || (str_contains($lower, 'challenge-platform') && str_contains($lower, 'just a moment'));
}

/**
 * @return array{status:string,note:?string}
 */
function interpret_ad_html(string $html, int $httpCode = 200, string $source = 'curl'): array
{
    $lower = strtolower($html);

    if (is_cloudflare_html($html, $httpCode)) {
        return ['status' => 'unknown', 'note' => 'blocked by Cloudflare challenge'];
    }

    if ($httpCode === 404 || $httpCode === 410) {
        return ['status' => 'removed', 'note' => $source . ': HTTP ' . $httpCode];
    }

    $removedSignals = [
        'page not found',
        'could not be found',
        'has probably expired',
        'this classified has been removed',
        'this ad has been removed',
        'this listing has been removed',
        'ad is no longer available',
        'classified is no longer available',
        'listing is no longer available',
        'this ad no longer exists',
        'ad not found',
        'classified not found',
        'no longer posted',
        'ad has expired',
        'classified ad, it has probably expired',
    ];

    foreach ($removedSignals as $signal) {
        if (str_contains($lower, $signal)) {
            return ['status' => 'removed', 'note' => $source . ': matched "' . $signal . '"'];
        }
    }

    // Only treat as active when real listing chrome is present.
    // Do NOT use generic "page has HTML content" — site 404 pages also have a full layout.
    $activeSignals = [
        'page view count',
        'problem with this ad',
        'report this ad',
        'posting id:',
        'posted by:',
        'ask ai to review this ad',
        'email to a friend',
    ];

    $activeHits = 0;
    foreach ($activeSignals as $signal) {
        if (str_contains($lower, $signal)) {
            $activeHits++;
        }
    }

    if ($activeHits >= 1 && ($httpCode === 0 || ($httpCode >= 200 && $httpCode < 400))) {
        return ['status' => 'active', 'note' => $source . ': active signals'];
    }

    // Title pattern for live ads: "... 63782877 | expatriates.com"
    if (
        preg_match('/,\s*\d{5,}\s*\|\s*expatriates\.com/i', $html)
        && !str_contains($lower, 'page not found')
        && ($httpCode === 0 || ($httpCode >= 200 && $httpCode < 400))
    ) {
        return ['status' => 'active', 'note' => $source . ': listing title'];
    }

    if ($httpCode >= 400) {
        return ['status' => 'removed', 'note' => $source . ': HTTP ' . $httpCode];
    }

    return ['status' => 'unknown', 'note' => $source . ': could not determine status'];
}

function find_node_binary(): ?string
{
    $candidates = [
        getenv('NODE_PATH') ?: null,
        '/usr/bin/node',
        '/usr/local/bin/node',
        trim((string) shell_exec('command -v node 2>/dev/null')),
    ];

    foreach ($candidates as $path) {
        if (is_string($path) && $path !== '' && is_executable($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Fetch page HTML using headless Chrome via Playwright.
 *
 * @return array{ok:bool,html:string,exit_code:int,error:?string}
 */
function fetch_via_browser(string $url): array
{
    $node = find_node_binary();
    if ($node === null) {
        return ['ok' => false, 'html' => '', 'exit_code' => 1, 'error' => 'node not found'];
    }

    if (!is_file(BROWSER_FETCH_JS)) {
        return ['ok' => false, 'html' => '', 'exit_code' => 1, 'error' => 'browser-fetch.js missing'];
    }

    $cmd = escapeshellarg($node)
        . ' '
        . escapeshellarg(BROWSER_FETCH_JS)
        . ' '
        . escapeshellarg($url);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = [
        'HOME' => __DIR__ . '/data/chrome-home',
        'CHROME_HOME' => __DIR__ . '/data/chrome-home',
        'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'LANG' => getenv('LANG') ?: 'en_US.UTF-8',
    ];

    if (getenv('CHROME_PATH')) {
        $env['CHROME_PATH'] = (string) getenv('CHROME_PATH');
    }

    $chromeHome = $env['CHROME_HOME'];
    if (!is_dir($chromeHome) && !mkdir($chromeHome, 0755, true) && !is_dir($chromeHome)) {
        return ['ok' => false, 'html' => '', 'exit_code' => 1, 'error' => 'cannot create chrome home dir'];
    }

    $process = proc_open($cmd, $descriptors, $pipes, __DIR__, $env);
    if (!is_resource($process)) {
        return ['ok' => false, 'html' => '', 'exit_code' => 1, 'error' => 'failed to start browser fetch'];
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $html = is_string($stdout) ? $stdout : '';
    $errorText = trim((string) $stderr);
    if (strlen($errorText) > 300) {
        $errorText = substr($errorText, 0, 300) . '…';
    }

    if ($exitCode === 1 || $html === '') {
        return [
            'ok' => false,
            'html' => $html,
            'exit_code' => $exitCode,
            'error' => $errorText !== '' ? $errorText : 'browser fetch failed',
        ];
    }

    return [
        'ok' => true,
        'html' => $html,
        'exit_code' => $exitCode,
        'error' => null,
    ];
}

/**
 * Check whether an expatriates.com ad page is still live.
 * Uses curl first; falls back to headless Chrome when Cloudflare blocks.
 *
 * @return array{status:string,note:?string}
 */
function check_ad_status(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 'unknown', 'note' => 'curl init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        // Network failure — still try browser fallback.
        $browser = fetch_via_browser($url);
        if ($browser['ok']) {
            $result = interpret_ad_html($browser['html'], 0, 'chrome');
            if ($result['status'] !== 'unknown') {
                return $result;
            }
        }

        return ['status' => 'unknown', 'note' => 'request failed: ' . $error];
    }

    $html = (string) $body;

    if (!is_cloudflare_html($html, $httpCode)) {
        return interpret_ad_html($html, $httpCode, 'curl');
    }

    // Cloudflare blocked curl — try headless Chrome.
    $browser = fetch_via_browser($url);
    if (!$browser['ok']) {
        return [
            'status' => 'unknown',
            'note' => 'Cloudflare blocked curl; browser fallback failed: ' . ($browser['error'] ?? 'unknown error'),
        ];
    }

    $result = interpret_ad_html($browser['html'], 0, 'chrome');
    if ($result['status'] === 'unknown' && $browser['exit_code'] === 2) {
        $result['note'] = 'Cloudflare challenge not cleared by Chrome';
    }

    return $result;
}

/**
 * @param array<string,mixed> $ad
 * @return array<string,mixed>
 */
function refresh_ad(array $ad): array
{
    $result = check_ad_status((string) $ad['url']);
    $ad['status'] = $result['status'];
    $ad['note'] = $result['note'];
    $ad['checked_at'] = gmdate('c');

    return $ad;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash_set(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * @return array{type:string,message:string}|null
 */
function flash_get(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}
