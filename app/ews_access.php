<?php

declare(strict_types=1);

// Setiap EWS dipetakan ke menu dashboard, halaman monitoring penuh, dan daftar stasiunnya.
function ews_types(): array
{
    return [
        'weather' => ['label' => 'EWS Cuaca', 'page' => 'weather-monitor', 'stations' => 'weather_stations'],
        'river' => ['label' => 'EWS Sungai', 'page' => 'river-monitor', 'stations' => 'river_stations'],
        'tide' => ['label' => 'EWS Pasang surut', 'page' => 'tide-monitor', 'stations' => 'tide_stations'],
        'tornado' => ['label' => 'EWS Tornado', 'page' => 'tornado-monitor', 'stations' => 'tornado_stations'],
    ];
}

function ews_labels(): array
{
    return array_map(static fn(array $type): string => $type['label'], ews_types());
}

// Daftar EWS yang ditetapkan ke pengguna; kosong berarti tidak dibatasi.
function user_assigned_ews(array $user): array
{
    if ($user['role'] === 'system_admin') {
        return [];
    }
    $stmt = db()->prepare('SELECT ews_code FROM user_ews WHERE user_id = ?');
    $stmt->execute([(int) $user['id']]);
    $assigned = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_filter(array_keys(ews_types()), static fn(string $code): bool => in_array($code, $assigned, true)));
}

function user_can_view_ews(array $user, string $type): bool
{
    if (!isset(ews_types()[$type])) {
        return false;
    }
    $assigned = user_assigned_ews($user);

    return $assigned === [] || in_array($type, $assigned, true);
}

// Pengguna dengan penugasan EWS langsung diarahkan ke halaman EWS pertamanya setelah login.
function ews_landing_url(array $user): string
{
    $assigned = user_assigned_ews($user);

    return $assigned === [] ? '/?page=dashboard' : '/?page=ews&type=' . $assigned[0];
}

function normalize_ews_codes(mixed $input): array
{
    if ($input === null || $input === '') {
        return [];
    }
    if (!is_array($input)) {
        throw new InvalidArgumentException('Pilihan EWS tidak valid.');
    }
    $codes = [];
    foreach ($input as $code) {
        if (!is_string($code) || !isset(ews_types()[$code])) {
            throw new InvalidArgumentException('Pilihan EWS tidak valid.');
        }
        $codes[$code] = true;
    }

    return array_keys($codes);
}

function save_user_ews(int $userId, array $codes): void
{
    db()->prepare('DELETE FROM user_ews WHERE user_id = ?')->execute([$userId]);
    $insert = db()->prepare('INSERT INTO user_ews (user_id, ews_code) VALUES (?, ?)');
    foreach ($codes as $code) {
        $insert->execute([$userId, $code]);
    }
}

function user_ews_map(): array
{
    $map = [];
    foreach (db()->query('SELECT user_id, ews_code FROM user_ews ORDER BY ews_code')->fetchAll() as $row) {
        $map[(int) $row['user_id']][] = $row['ews_code'];
    }

    return $map;
}

function ews_stations_for(array $user, string $type): array
{
    $fn = ews_types()[$type]['stations'];

    return $fn($user);
}

// Halaman EWS: arahkan ke stasiun pertama (atau yang diminta) dari EWS terpilih.
function handle_ews_home(array $user): never
{
    $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
    if (!user_can_view_ews($user, $type)) {
        $assigned = user_assigned_ews($user);
        if ($assigned === []) {
            http_response_code(404);
            render_access_denied();
            exit;
        }
        redirect_to('/?page=ews&type=' . $assigned[0]);
    }
    $stations = ews_stations_for($user, $type);
    if ($stations === []) {
        render_ews_empty($user, $type);
        exit;
    }
    redirect_to('/?page=' . ews_types()[$type]['page'] . '&code=' . rawurlencode((string) $stations[0]['code']));
}

// Bilah atas halaman monitoring: pindah EWS, pindah stasiun, dan akun pengguna.
function render_ews_bar(string $type, string $currentCode): void
{
    $user = signed_in_user();
    if ($user === null) {
        return;
    }
    $types = ews_types();
    $assigned = user_assigned_ews($user);
    $tabs = $assigned === [] ? array_keys($types) : $assigned;
    $stations = ews_stations_for($user, $type);
    ?>
    <nav class="ews-bar" aria-label="Navigasi EWS">
        <div class="ews-tabs">
            <?php foreach ($tabs as $code): ?>
                <a class="<?= $code === $type ? 'active' : '' ?>" href="/?page=ews&amp;type=<?= e($code) ?>"><?= e($types[$code]['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="ews-account">
            <a href="/?page=dashboard">Dashboard</a>
            <span><?= e($user['name']) ?></span>
            <form method="post" action="/?page=logout">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button type="submit">Keluar</button>
            </form>
        </div>
    </nav>
    <?php if (count($stations) > 1): ?>
        <div class="ews-stations" aria-label="Pilih stasiun">
            <?php foreach ($stations as $station): ?>
                <a class="<?= $station['code'] === $currentCode ? 'active' : '' ?>" href="/?page=<?= e($types[$type]['page']) ?>&amp;code=<?= e(rawurlencode((string) $station['code'])) ?>"><?= e($station['name']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif;
}

function render_ews_empty(array $user, string $type): void
{
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e(ews_types()[$type]['label']) ?> · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
    </head>
    <body class="monitor-body">
    <main class="monitor-page">
        <?php render_ews_bar($type, ''); ?>
        <section class="panel monitor-table"><h2><?= e(ews_types()[$type]['label']) ?></h2>
            <p>Belum ada stasiun aktif dalam cakupan wilayah Anda untuk EWS ini.</p></section>
    </main>
    </body>
    </html>
    <?php
}
