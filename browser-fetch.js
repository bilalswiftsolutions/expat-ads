#!/usr/bin/env node
'use strict';

/**
 * Fetch a URL with real Chrome, waiting out Cloudflare challenges.
 * Prints final HTML to stdout only after Active or Removed signals appear
 * (or after timeout).
 *
 * Exit codes:
 *   0 = page fetched with decisive content
 *   1 = hard failure (chrome missing / crash)
 *   2 = still on Cloudflare challenge / no decisive content after timeout
 *
 * Usage: node browser-fetch.js <url>
 */

const { chromium } = require('playwright-core');
const fs = require('fs');
const { execSync } = require('child_process');

const url = process.argv[2];
if (!url) {
  console.error('Usage: node browser-fetch.js <url>');
  process.exit(1);
}

const CHROME_CANDIDATES = [
  process.env.CHROME_PATH,
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
  '/snap/bin/chromium',
  '/usr/lib/chromium/chromium',
  '/usr/lib/chromium-browser/chromium-browser',
].filter(Boolean);

function findChrome() {
  for (const path of CHROME_CANDIDATES) {
    try {
      fs.accessSync(path, fs.constants.X_OK);
      return path;
    } catch {
      // try next
    }
  }

  // Last resort: resolve from PATH (works for www-data cron if PATH includes chrome).
  for (const name of ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser']) {
    try {
      const resolved = execSync(`command -v ${name}`, {
        encoding: 'utf8',
        timeout: 3000,
      }).trim();
      if (resolved) {
        return resolved;
      }
    } catch {
      // try next
    }
  }

  return null;
}

function isCloudflareChallenge(html, title) {
  const t = (title || '').toLowerCase();
  if (t.includes('just a moment')) {
    return true;
  }

  const lower = (html || '').toLowerCase();
  return (
    lower.includes('performing security verification') ||
    (lower.includes('cf-turnstile') && t.includes('just a moment'))
  );
}

function isRemovedPage(html, title) {
  const lower = (html || '').toLowerCase();
  const t = (title || '').toLowerCase();
  return (
    t.includes('page not found') ||
    lower.includes('page not found') ||
    lower.includes('could not be found') ||
    lower.includes('has probably expired')
  );
}

function isActivePage(html, title) {
  const lower = (html || '').toLowerCase();
  const t = (title || '').toLowerCase();

  if (isRemovedPage(html, title) || isCloudflareChallenge(html, title)) {
    return false;
  }

  const bodySignals = [
    'page view count',
    'problem with this ad',
    'report this ad',
    'posting id:',
    'posted by:',
    'ask ai to review this ad',
    'email to a friend',
  ];

  if (bodySignals.some((s) => lower.includes(s))) {
    return true;
  }

  // Title like: "... 63782877 | expatriates.com"
  return /,\s*\d{5,}\s*\|\s*expatriates\.com/i.test(t);
}

function hasDecisiveContent(html, title) {
  return isRemovedPage(html, title) || isActivePage(html, title);
}

(async () => {
  const executablePath = findChrome();
  if (!executablePath) {
    console.error('Chrome/Chromium not found. Set CHROME_PATH.');
    process.exit(1);
  }

  const browser = await chromium.launch({
    executablePath,
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-dev-shm-usage',
      '--disable-blink-features=AutomationControlled',
    ],
  });

  try {
    const context = await browser.newContext({
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
      locale: 'en-US',
      viewport: { width: 1365, height: 900 },
    });

    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    });

    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

    const deadline = Date.now() + 45000;
    let html = '';
    let title = '';

    while (Date.now() < deadline) {
      title = await page.title();
      html = await page.content();

      // Wait until we have Active OR Removed evidence — do not exit early
      // just because Cloudflare UI disappeared (body may still be loading).
      if (!isCloudflareChallenge(html, title) && hasDecisiveContent(html, title)) {
        process.stdout.write(html);
        process.exit(0);
      }

      await page.waitForTimeout(1000);
    }

    title = await page.title();
    html = await page.content();
    process.stdout.write(html);

    if (isCloudflareChallenge(html, title) || !hasDecisiveContent(html, title)) {
      process.exit(2);
    }
    process.exit(0);
  } catch (err) {
    console.error(String(err && err.message ? err.message : err));
    process.exit(1);
  } finally {
    await browser.close();
  }
})();
