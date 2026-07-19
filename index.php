<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

session_start();

$ads = load_ads();
$editingId = isset($_GET['edit']) ? (string) $_GET['edit'] : null;
$editingAd = null;

if ($editingId !== null) {
    $idx = find_ad_index($ads, $editingId);
    if ($idx >= 0) {
        $editingAd = $ads[$idx];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = (string) ($_POST['_token'] ?? '');
    $sessionToken = (string) ($_SESSION['_token'] ?? '');

    if ($token === '' || !hash_equals($sessionToken, $token)) {
        flash_set('error', 'Invalid form token. Please try again.');
        header('Location: index.php');
        exit;
    }

    // Chrome fallback can take ~30–60s per ad (also used on add/update).
    if (in_array($action, ['add', 'update', 'check', 'check_all'], true)) {
        ignore_user_abort(true);
        set_time_limit($action === 'check_all' ? 0 : 120);
    }

    try {
        if ($action === 'add') {
            $url = normalize_url((string) ($_POST['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Please enter a valid URL.');
            }
            if (!is_valid_expat_url($url)) {
                throw new InvalidArgumentException('URL must look like https://www.expatriates.com/cls/12345678.html');
            }

            foreach ($ads as $ad) {
                if (strcasecmp((string) $ad['url'], $url) === 0) {
                    throw new InvalidArgumentException('This URL is already in the list.');
                }
            }

            $ad = [
                'id' => bin2hex(random_bytes(8)),
                'url' => $url,
                'status' => 'unknown',
                'note' => null,
                'checked_at' => null,
                'created_at' => gmdate('c'),
            ];
            $ad = refresh_ad($ad);
            $ads[] = $ad;
            save_ads($ads);
            $status = strtoupper((string) $ad['status']);
            flash_set('success', "Ad link added. Status: {$status}");
        } elseif ($action === 'update') {
            $id = (string) ($_POST['id'] ?? '');
            $url = normalize_url((string) ($_POST['url'] ?? ''));
            $status = normalize_status((string) ($_POST['status'] ?? 'unknown'));
            $recheck = isset($_POST['recheck']) && (string) $_POST['recheck'] === '1';
            $idx = find_ad_index($ads, $id);

            if ($idx < 0) {
                throw new InvalidArgumentException('Ad not found.');
            }
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Please enter a valid URL.');
            }
            if (!is_valid_expat_url($url)) {
                throw new InvalidArgumentException('URL must look like https://www.expatriates.com/cls/12345678.html');
            }

            foreach ($ads as $i => $ad) {
                if ($i !== $idx && strcasecmp((string) $ad['url'], $url) === 0) {
                    throw new InvalidArgumentException('This URL is already in the list.');
                }
            }

            $ads[$idx]['url'] = $url;

            if ($recheck) {
                $ads[$idx] = refresh_ad($ads[$idx]);
                save_ads($ads);
                $status = strtoupper((string) $ads[$idx]['status']);
                flash_set('success', "Ad link updated and rechecked. Status: {$status}");
            } else {
                $ads[$idx]['status'] = $status;
                $ads[$idx]['note'] = 'status set manually';
                $ads[$idx]['checked_at'] = gmdate('c');
                save_ads($ads);
                flash_set(
                    'success',
                    'Ad link updated. Status: ' . strtoupper($status) . ' (manual — cron will overwrite on next run)'
                );
            }
        } elseif ($action === 'delete') {
            $id = (string) ($_POST['id'] ?? '');
            $idx = find_ad_index($ads, $id);
            if ($idx < 0) {
                throw new InvalidArgumentException('Ad not found.');
            }
            array_splice($ads, $idx, 1);
            save_ads($ads);
            flash_set('success', 'Ad link deleted.');
        } elseif ($action === 'check') {
            $id = (string) ($_POST['id'] ?? '');
            $idx = find_ad_index($ads, $id);
            if ($idx < 0) {
                throw new InvalidArgumentException('Ad not found.');
            }
            $ads[$idx] = refresh_ad($ads[$idx]);
            save_ads($ads);
            $status = strtoupper((string) $ads[$idx]['status']);
            flash_set('success', "Checked. Status: {$status}");
        } elseif ($action === 'check_all') {
            $checked = 0;
            foreach ($ads as $i => $ad) {
                $ads[$i] = refresh_ad($ad);
                $checked++;
                if ($checked < count($ads)) {
                    sleep(2);
                }
            }
            save_ads($ads);
            flash_set('success', "Checked {$checked} ads.");
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    header('Location: index.php');
    exit;
}

if (empty($_SESSION['_token'])) {
    $_SESSION['_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['_token'];
$flash = flash_get();

$counts = ['active' => 0, 'removed' => 0, 'unknown' => 0];
foreach ($ads as $ad) {
    $status = (string) ($ad['status'] ?? 'unknown');
    if (!isset($counts[$status])) {
        $counts[$status] = 0;
    }
    $counts[$status]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Expatriates Ad Tracker</title>
    <style>
        :root {
            --bg: #f3f0ea;
            --ink: #1c1917;
            --muted: #78716c;
            --line: #d6d3d1;
            --card: #fffcf7;
            --accent: #0f766e;
            --accent-ink: #fff;
            --active: #166534;
            --active-bg: #dcfce7;
            --removed: #991b1b;
            --removed-bg: #fee2e2;
            --unknown: #92400e;
            --unknown-bg: #fef3c7;
            --danger: #b91c1c;
            --shadow: 0 10px 30px rgba(28, 25, 23, 0.06);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(15, 118, 110, 0.10), transparent 40%),
                linear-gradient(180deg, #faf7f2 0%, var(--bg) 100%);
            min-height: 100vh;
        }

        .wrap {
            width: min(1100px, calc(100% - 2rem));
            margin: 0 auto;
            padding: 2rem 0 3rem;
        }

        header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: end;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        h1 {
            margin: 0 0 0.25rem;
            font-size: 1.75rem;
            letter-spacing: -0.02em;
        }

        .sub {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .stats {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        .stat {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 0.35rem 0.8rem;
            font-size: 0.85rem;
            box-shadow: var(--shadow);
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 1.1rem;
            box-shadow: var(--shadow);
            margin-bottom: 1rem;
        }

        .panel h2 {
            margin: 0 0 0.8rem;
            font-size: 1.05rem;
        }

        form.row {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        input[type="url"], input[type="text"], select {
            flex: 1 1 280px;
            min-width: 0;
            padding: 0.7rem 0.85rem;
            border: 1px solid var(--line);
            border-radius: 10px;
            font: inherit;
            background: #fff;
        }

        select {
            flex: 0 1 160px;
        }

        .edit-options {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem 1.2rem;
            align-items: center;
            width: 100%;
            margin-top: 0.15rem;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .edit-options label {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            cursor: pointer;
        }

        button, .btn {
            border: 0;
            border-radius: 10px;
            padding: 0.7rem 1rem;
            font: inherit;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            white-space: nowrap;
        }

        .btn-primary { background: var(--accent); color: var(--accent-ink); }
        .btn-muted { background: #e7e5e4; color: var(--ink); }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-small { padding: 0.4rem 0.7rem; font-size: 0.85rem; border-radius: 8px; }

        .flash {
            padding: 0.8rem 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            border: 1px solid transparent;
        }

        .flash.success { background: var(--active-bg); color: var(--active); border-color: #bbf7d0; }
        .flash.error { background: var(--removed-bg); color: var(--removed); border-color: #fecaca; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        th, td {
            text-align: left;
            padding: 0.75rem 0.6rem;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
            font-size: 0.92rem;
        }

        th {
            color: var(--muted);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        a.link {
            color: var(--accent);
            word-break: break-all;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .badge.active { background: var(--active-bg); color: var(--active); }
        .badge.removed { background: var(--removed-bg); color: var(--removed); }
        .badge.unknown { background: var(--unknown-bg); color: var(--unknown); }

        .actions {
            display: flex;
            gap: 0.35rem;
            flex-wrap: wrap;
        }

        .meta {
            color: var(--muted);
            font-size: 0.8rem;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            gap: 0.8rem;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 0.8rem;
        }

        .empty {
            padding: 2rem 1rem;
            text-align: center;
            color: var(--muted);
        }

        footer {
            margin-top: 1.25rem;
            color: var(--muted);
            font-size: 0.85rem;
        }

        code {
            background: #ebe7e0;
            padding: 0.1rem 0.35rem;
            border-radius: 4px;
            font-size: 0.85em;
        }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1>Expatriates Ad Tracker</h1>
            <p class="sub">One-page CRUD for expatriates.com classified links, with live status checks.</p>
        </div>
        <div class="stats">
            <div class="stat">Total: <?= count($ads) ?></div>
            <div class="stat">Active: <?= (int) $counts['active'] ?></div>
            <div class="stat">Removed: <?= (int) $counts['removed'] ?></div>
            <div class="stat">Unknown: <?= (int) $counts['unknown'] ?></div>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2><?= $editingAd ? 'Edit ad link' : 'Add new ad link' ?></h2>
        <form class="row" method="post" action="index.php">
            <input type="hidden" name="_token" value="<?= h($token) ?>">
            <?php if ($editingAd): ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= h((string) $editingAd['id']) ?>">
            <?php else: ?>
                <input type="hidden" name="action" value="add">
            <?php endif; ?>
            <input
                type="url"
                name="url"
                required
                placeholder="https://www.expatriates.com/cls/63782887.html"
                value="<?= h($editingAd ? (string) $editingAd['url'] : '') ?>"
            >
            <?php if ($editingAd): ?>
                <?php $editStatus = (string) ($editingAd['status'] ?? 'unknown'); ?>
                <select name="status" aria-label="Status">
                    <?php foreach (allowed_statuses() as $statusOption): ?>
                        <option value="<?= h($statusOption) ?>" <?= $editStatus === $statusOption ? 'selected' : '' ?>>
                            <?= h(ucfirst($statusOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button class="btn-primary" type="submit"><?= $editingAd ? 'Save changes' : 'Add URL' ?></button>
            <?php if ($editingAd): ?>
                <a class="btn btn-muted" href="index.php">Cancel</a>
                <div class="edit-options">
                    <label>
                        <input type="checkbox" name="recheck" value="1">
                        Also recheck live status after save
                    </label>
                    <span>Leave unchecked to set status manually and test that cron overwrites it.</span>
                </div>
            <?php endif; ?>
        </form>
    </section>

    <section class="panel">
        <div class="toolbar">
            <h2 style="margin:0">All ad links</h2>
            <form method="post" action="index.php" onsubmit="return confirm('Check status of all ads now? This may take a while.');">
                <input type="hidden" name="_token" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="check_all">
                <button class="btn-muted btn-small" type="submit" <?= count($ads) === 0 ? 'disabled' : '' ?>>
                    Check all now
                </button>
            </form>
        </div>

        <?php if (count($ads) === 0): ?>
            <div class="empty">No ads yet. Add your first expatriates.com link above.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>URL</th>
                        <th>Status</th>
                        <th>Last checked</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ads as $i => $ad): ?>
                        <?php
                        $status = (string) ($ad['status'] ?? 'unknown');
                        $checkedAt = $ad['checked_at'] ?? null;
                        $checkedLabel = $checkedAt
                            ? date('Y-m-d H:i', strtotime((string) $checkedAt)) . ' UTC'
                            : 'Never';
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <a class="link" href="<?= h((string) $ad['url']) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= h((string) $ad['url']) ?>
                                </a>
                                <?php if (!empty($ad['note'])): ?>
                                    <div class="meta"><?= h((string) $ad['note']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= h($status) ?>">
                                    <?= h(ucfirst($status)) ?>
                                </span>
                            </td>
                            <td class="meta"><?= h($checkedLabel) ?></td>
                            <td>
                                <div class="actions">
                                    <form method="post" action="index.php">
                                        <input type="hidden" name="_token" value="<?= h($token) ?>">
                                        <input type="hidden" name="action" value="check">
                                        <input type="hidden" name="id" value="<?= h((string) $ad['id']) ?>">
                                        <button class="btn-primary btn-small" type="submit">Check</button>
                                    </form>
                                    <a class="btn btn-muted btn-small" href="index.php?edit=<?= h((string) $ad['id']) ?>">Edit</a>
                                    <form method="post" action="index.php" onsubmit="return confirm('Delete this ad link?');">
                                        <input type="hidden" name="_token" value="<?= h($token) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= h((string) $ad['id']) ?>">
                                        <button class="btn-danger btn-small" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <footer>
        Cron every 10 minutes:
        <code>*/10 * * * * php <?= h(__DIR__ . '/check.php') ?> >> <?= h(__DIR__ . '/data/cron.log') ?> 2>&amp;1</code>
    </footer>
</div>
</body>
</html>
