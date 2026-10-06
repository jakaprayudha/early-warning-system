<?php

declare(strict_types=1);

function health_sensor_rows(array $user): array
{
    $now = time();
    $rows = [];
    foreach (list_sensors($user) as $sensor) {
        $last = max((int) ($sensor['last_heartbeat_at'] ?? 0), (int) ($sensor['last_data_at'] ?? 0));
        $interval = (int) $sensor['expected_interval_minutes'] * 60;
        $sensor['last_seen'] = $last > 0 ? $last : null;
        $sensor['overdue_seconds'] = $last > 0 && $sensor['health'] === 'delayed' ? $now - $last - $interval : 0;
        $rows[] = $sensor;
    }
    $order = ['delayed' => 0, 'unknown' => 1, 'maintenance' => 2, 'healthy' => 3, 'inactive' => 4];
    usort($rows, static fn(array $a, array $b): int => [$order[$a['health']], -$a['overdue_seconds']] <=> [$order[$b['health']], -$b['overdue_seconds']]);

    return $rows;
}

function health_service_checks(): array
{
    $checks = [];
    $path = dirname(__DIR__) . '/storage';
    $start = microtime(true);
    try {
        $ok = db()->query('SELECT 1')->fetchColumn() === 1;
        $latency = (int) round((microtime(true) - $start) * 1000);
        $checks[] = ['Database', $ok ? 'ok' : 'fail', $ok ? 'Terhubung, respons ' . $latency . ' ms.' : 'Query uji gagal.'];
        $integrity = (string) db()->query('PRAGMA quick_check')->fetchColumn();
        $checks[] = ['Integritas database', $integrity === 'ok' ? 'ok' : 'fail', $integrity === 'ok' ? 'Pemeriksaan cepat lolos.' : 'Ditemukan masalah integritas.'];
        $fk = db()->query('PRAGMA foreign_key_check')->fetchAll();
        $checks[] = ['Relasi data', $fk === [] ? 'ok' : 'warn', $fk === [] ? 'Tidak ada relasi yatim.' : count($fk) . ' baris dengan relasi tidak valid.'];
    } catch (Throwable) {
        $checks[] = ['Database', 'fail', 'Tidak dapat terhubung.'];
    }
    $checks[] = ['Penyimpanan', is_writable($path) ? 'ok' : 'fail', is_writable($path) ? 'Folder penyimpanan dapat ditulis.' : 'Folder penyimpanan tidak dapat ditulis.'];
    $free = @disk_free_space($path);
    $total = @disk_total_space($path);
    if ($free !== false && $total) {
        $pct = (int) round($free / $total * 100);
        $checks[] = ['Ruang disk', $pct < 5 ? 'fail' : ($pct < 15 ? 'warn' : 'ok'), $pct . '% kosong (' . round($free / 1073741824, 1) . ' GB).'];
    }
    $file = $path . '/db_ews.sqlite';
    if (is_file($file)) {
        $checks[] = ['Ukuran database', 'ok', round(filesize($file) / 1048576, 2) . ' MB.'];
    }
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $checks[] = ['Koneksi aman (HTTPS)', $https ? 'ok' : 'warn', $https ? 'Permintaan memakai HTTPS.' : 'Permintaan ini tidak memakai HTTPS; wajib untuk produksi.'];
    $checks[] = ['Versi PHP', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', PHP_VERSION];
    $missing = array_filter(['pdo_sqlite', 'mbstring', 'json'], static fn(string $ext): bool => !extension_loaded($ext));
    $checks[] = ['Ekstensi PHP', $missing === [] ? 'ok' : 'fail', $missing === [] ? 'pdo_sqlite, mbstring, json tersedia.' : 'Tidak ada: ' . implode(', ', $missing)];

    return $checks;
}

function health_ingest_stats(array $user): array
{
    $rows = [];
    foreach ([['1 jam', 3600], ['24 jam', 86400]] as [$label, $seconds]) {
        $params = [time() - $seconds];
        $scope = ingest_scope_filter($user, $params);
        $statement = db()->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(sensor_readings.status IN ("accepted", "late")) AS good,
                    MAX(sensor_readings.received_at) AS latest
             FROM sensor_readings JOIN sensors ON sensors.id = sensor_readings.sensor_id
             JOIN monitoring_locations ON monitoring_locations.id = sensors.location_id
             WHERE sensor_readings.received_at >= ? AND ' . $scope
        );
        $statement->execute($params);
        $row = $statement->fetch();
        $rows[$label] = ['total' => (int) $row['total'], 'good' => (int) $row['good'], 'latest' => $row['latest'] === null ? null : (int) $row['latest']];
    }

    return $rows;
}

function health_overview(array $sensors, array $checks): array
{
    $counts = array_fill_keys(array_keys(sensor_health_labels()), 0);
    foreach ($sensors as $sensor) {
        $counts[$sensor['health']]++;
    }
    $failed = count(array_filter($checks, static fn(array $c): bool => $c[1] === 'fail'));
    $warned = count(array_filter($checks, static fn(array $c): bool => $c[1] === 'warn'));
    $level = $failed > 0 || $counts['delayed'] > 0 ? 'bad' : ($warned > 0 || $counts['unknown'] > 0 ? 'warn' : 'ok');

    return ['counts' => $counts, 'level' => $level, 'failed' => $failed, 'warned' => $warned];
}
