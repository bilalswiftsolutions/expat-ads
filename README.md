# Expatriates Ad Tracker

Simple one-page PHP app to track [expatriates.com](https://www.expatriates.com) classified ad links, check whether each ad is still **Active** or **Removed**, and keep status in a JSON file.

Live example subdomain: `expat-status.bilalarshad.pro`

---

## What it does

- Single page UI for all ads
- **CRUD**: add, edit, delete ad URLs
- **Status check** on add/edit, manual check, check-all, and cron
- Status colors:
  - **Active** (green) — listing page is live
  - **Removed** (red) — page not found / expired
  - **Unknown** (amber) — could not determine (e.g. Cloudflare still blocking)
- Newest ads appear at the top
- Data stored in `data/ads.json` (no database)

Example URL format:

```text
https://www.expatriates.com/cls/63782887.html
```

---

## How it works

### Flow

1. You add or edit a URL on `index.php`.
2. The app immediately checks that URL and saves status.
3. Every 10 minutes, `check.php` rechecks all stored URLs (via cron).
4. Results are written back to `data/ads.json`.

### Status check logic

1. **curl first** — fast HTTP fetch of the ad page.
2. If **Cloudflare** blocks curl (`Just a moment...` / challenge), fall back to **headless Chrome** via Playwright (`browser-fetch.js`).
3. Chrome waits until the page shows clear **Active** or **Removed** signals (not an incomplete load).
4. PHP interprets the HTML:
   - **Removed** signals: `Page Not Found`, `could not be found`, `has probably expired`, etc.
   - **Active** signals: `Page View Count`, `Problem with this ad`, `Posting ID:`, `Posted by:`, listing title pattern, etc.

### Files

| File | Role |
|------|------|
| `index.php` | One-page UI + add / edit / delete / check |
| `lib.php` | Load/save JSON, status detection, Chrome fallback |
| `check.php` | CLI cron script — recheck all ads |
| `browser-fetch.js` | Playwright + Chrome fetch (Cloudflare bypass) |
| `data/ads.json` | Stored links + status (created locally / on server) |
| `data/ads.json.example` | Empty starter file for deploy |
| `deploy/nginx-expat-status.conf` | Nginx config for the subdomain |

### Data shape (`data/ads.json`)

```json
[
  {
    "id": "a1b2c3d4e5f60718",
    "url": "https://www.expatriates.com/cls/63782887.html",
    "status": "active",
    "note": "chrome: active signals",
    "checked_at": "2026-07-19T15:28:14+00:00",
    "created_at": "2026-07-19T15:02:24+00:00"
  }
]
```

---

## Requirements

- PHP 8.1+ with `curl` extension
- Node.js (for Playwright)
- Google Chrome or Chromium (for Cloudflare fallback)
- Nginx + PHP-FPM (for production)

---

## Local development

```bash
cd /path/to/expat-ads
cp data/ads.json.example data/ads.json
npm install
php -S 127.0.0.1:8765
```

Open: [http://127.0.0.1:8765](http://127.0.0.1:8765)

Manual CLI check of all ads:

```bash
php check.php
```

---

## Cron (every 10 minutes)

```cron
*/10 * * * * /usr/bin/php /var/www/expat-status/check.php >> /var/www/expat-status/data/cron.log 2>&1
```

On the server, run this as the same user that owns the app files (usually `www-data`):

```bash
sudo crontab -u www-data -e
```

---

## Deploy to `expat-status.bilalarshad.pro`

### 1. DNS

Create an **A** record:

| Type | Name | Value |
|------|------|--------|
| A | `expat-status` | your server public IP |

Check:

```bash
dig +short expat-status.bilalarshad.pro
```

### 2. Install packages (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-curl php8.3-cli git nodejs npm
# Chrome (pick one that matches your OS)
sudo apt install -y google-chrome-stable
# or: sudo apt install -y chromium-browser
```

If PHP is not 8.3, change the version in the Nginx config (`fastcgi_pass`).

### 3. Clone and prepare the app

```bash
sudo mkdir -p /var/www/expat-status
sudo git clone https://github.com/bilalswiftsolutions/expat-ads.git /var/www/expat-status
cd /var/www/expat-status

sudo npm install --omit=dev
sudo cp data/ads.json.example data/ads.json

sudo chown -R www-data:www-data /var/www/expat-status
sudo chmod -R u+rwX /var/www/expat-status/data
```

Optional: if Chrome is not at `/usr/bin/google-chrome`, set:

```bash
# e.g. in /etc/environment or the cron line
export CHROME_PATH=/usr/bin/chromium-browser
```

### 4. Nginx

```bash
sudo cp /var/www/expat-status/deploy/nginx-expat-status.conf \
  /etc/nginx/sites-available/expat-status

# Edit PHP-FPM socket if needed:
#   unix:/run/php/php8.3-fpm.sock
#   unix:/run/php/php8.2-fpm.sock
#   unix:/run/php/php8.1-fpm.sock

sudo ln -sf /etc/nginx/sites-available/expat-status /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Config highlights:

- `root /var/www/expat-status`
- `server_name expat-status.bilalarshad.pro`
- `/data/` blocked from public access
- `fastcgi_read_timeout 180s` (Chrome checks can be slow)

### 5. HTTPS (Let’s Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d expat-status.bilalarshad.pro
```

### 6. Cron

```bash
sudo crontab -u www-data -e
```

Add:

```cron
*/10 * * * * /usr/bin/php /var/www/expat-status/check.php >> /var/www/expat-status/data/cron.log 2>&1
```

### 7. Verify

1. Open https://expat-status.bilalarshad.pro
2. Add an ad URL — status should become Active or Removed (may take a few seconds)
3. Confirm cron log:

```bash
sudo tail -f /var/www/expat-status/data/cron.log
```

---

## Updating after code changes

```bash
cd /var/www/expat-status
sudo -u www-data git pull
sudo npm install --omit=dev
# ads.json is local and not overwritten by git
```

---

## Troubleshooting

| Problem | Likely cause | Fix |
|---------|----------------|-----|
| Status **Unknown** + note `Chrome/Chromium not found` | Chrome not installed on server | Install Chrome/Chromium (see below) |
| Status stuck **Unknown** otherwise | Cloudflare / incomplete page | Ensure `npm install` ran; test `node browser-fetch.js <url>` |
| Add/check hangs then fails | PHP timeout | Nginx `fastcgi_read_timeout 180s`; PHP `max_execution_time` high enough |
| Cannot write status | Permissions | `chown -R www-data:www-data` and writable `data/` |
| 404 on site | Nginx root / DNS | Confirm DNS A record and `root` path |
| `ads.json` missing | First deploy | `cp data/ads.json.example data/ads.json` |

### Fix: Chrome missing on server (most common Unknown cause)

The UI note looks like:

```text
Cloudflare blocked curl; browser fallback failed: Chrome/Chromium not found. Set CHROME_PATH.
```

Install Chrome (recommended on Ubuntu):

```bash
# Google Chrome
wget -q -O /tmp/chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
sudo apt install -y /tmp/chrome.deb

# Verify
which google-chrome || which google-chrome-stable
google-chrome --version
```

Or Chromium:

```bash
sudo apt install -y chromium-browser
# or
sudo apt install -y chromium
which chromium || which chromium-browser
```

Also ensure Node deps exist:

```bash
cd /var/www/expat-status
sudo npm install --omit=dev
sudo chown -R www-data:www-data /var/www/expat-status
```

Test as the web user:

```bash
sudo -u www-data bash -lc 'cd /var/www/expat-status && node browser-fetch.js "https://www.expatriates.com/cls/63782887.html" | head -c 400'
```

If Chrome is installed in a custom path:

```bash
# for PHP-FPM / cron
echo 'CHROME_PATH=/usr/bin/google-chrome-stable' | sudo tee /etc/environment
# or put it in the cron line:
# */10 * * * * CHROME_PATH=/usr/bin/google-chrome-stable /usr/bin/php /var/www/expat-status/check.php >> ...
```

Then click **Check** again in the UI.
---

## Security notes

- `data/` must not be publicly readable (Nginx blocks it; `.htaccess` also denies if Apache is used)
- This UI has **no login** — protect the subdomain (VPN, firewall IP allowlist, or basic auth) if the URL is private
- Do not commit real `data/ads.json` (already in `.gitignore`)

---

## License

ISC
